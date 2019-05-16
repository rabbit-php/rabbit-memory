<?php
/**
 * Created by PhpStorm.
 * User: Administrator
 * Date: 2018/10/25
 * Time: 0:14
 */

namespace rabbit\memory\table;


use Psr\SimpleCache\CacheInterface;
use rabbit\App;
use rabbit\parser\ParserInterface;
use Swoole\Serialize;

/**
 * Class TableCache
 * @package rabbit\memory\table
 */
class TableCache implements CacheInterface
{
    /**
     * @var ParserInterface|null
     */
    private $serializer = null;

    /**
     * @var Table
     */
    private $tableInstance;

    /**
     * @var int
     */
    private $dataLength = 8192;

    /**
     * the max expire of cache limited by this value
     * @var int
     */
    private $maxLive = 3000000;
    /**
     * @var int Gc process will seelp $gcSleep second each 100000 times
     */
    private $gcSleep = 0.01;
    /**
     * @var int the probability (parts per million) that garbage collection (GC) should be performed
     * when storing a piece of data in the cache. Defaults to 100, meaning 0.01% chance.
     * This number should be between 0 and 1000000. A value 0 meaning no GC will be performed at all.
     */
    private $gcProbability = 100;

    /**
     * TableCache constructor.
     * @param int $size
     * @param int $dataLength
     */
    public function __construct(int $size = 1024, int $dataLength = 8192, ParserInterface $serializer = null)
    {
        $app = App::getApp();
        if (!property_exists($app, 'tableCache')) {
            $this->tableInstance = $app->tableCache = $this->initCacheTable($size, $dataLength);
        } else {
            $this->tableInstance = $app->tableCache;
        }
        $this->serializer = $serializer;
        $this->dataLength = $dataLength;
    }

    /**
     * @param int $size
     * @param int $dataLength
     */
    private function initCacheTable(int $size, int $dataLength): Table
    {
        $table = new Table('cache', $size);
        $table->column('expire', Table::TYPE_STRING, 11);
        $table->column('nextId', Table::TYPE_STRING, 35);
        $table->column('data', Table::TYPE_STRING, $dataLength);
        $table->create();
        return $table;
    }

    /**
     * @param string $key
     * @param null $default
     * @return bool|mixed|null|string
     */
    public function get($key, $default = null)
    {
        $value = $this->getValue($key);
        if ($value === false) {
            return $value;
        } elseif ($this->serializer === null) {
            return Serialize::unpack($value);
        } else {
            $value = $this->serializer->decode($value);
        }

        return $default;
    }

    /**
     * @param $key
     * @param null $nowtime
     * @return bool|string
     */
    private function getValue($key, $nowtime = null)
    {
        if (empty($key)) {
            return '';
        }
        if (empty($nowtime)) {
            $nowtime = time();
        }
        $column = $this->tableInstance->get($key);
        if ($column == false) {
            return false;
        }
        if ($column['expire'] != 0 && $column['expire'] < $nowtime) {
            $this->deleteValue($key);
            return false;
        }
        $nextValue = $this->getValue($column['nextId'], $nowtime);
        if ($nextValue === false) {
            $this->tableInstance->del($key);
            return false;
        }
        return $column['data'] . $nextValue;
    }

    /**
     * @param $keys
     * @return array
     */
    protected function getValues($keys)
    {
        $results = [];
        foreach ($keys as $key) {
            $results[$key] = $this->getValue($key);
        }

        return $results;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param null $ttl
     * @return bool
     */
    public function set($key, $value, $ttl = null)
    {
        if ($this->serializer === null) {
            $value = Serialize::pack($value);
        } else {
            $value = $this->serializer->encode($value);
        }

        return $this->setValue($key, $value, $ttl);
    }

    /**
     * @param $key
     * @param $value
     * @param $duration
     * @return bool
     */
    private function setValue($key, $value, $duration): bool
    {
        $this->gc();
        $expire = $duration ? $duration + time() : 0;
        $valueLength = strlen($value);
        return (boolean)$this->setValueRec($key, $value, $expire, $valueLength);
    }

    /**
     * @param $key
     * @param $value
     * @param $expire
     * @param $valueLength
     * @param int $num
     * @return bool|string
     */
    private function setValueRec($key, &$value, $expire, $valueLength, $num = 0)
    {
        $start = $num * $this->dataLength;
        if ($start > $valueLength) {
            return '';
        }
        $nextNum = $num + 1;
        $nextId = $this->setValueRec($key, $value, $expire, $valueLength, $nextNum);
        if ($nextId === false) {
            return false;
        }
        if ($num) {
            $setKey = $key . $num;
        } else {
            $setKey = $key;
        }
        $result = $this->tableInstance->set($setKey, [
            'expire' => $expire,
            'nextId' => $nextId,
            'data' => substr($value, $start, $this->dataLength)
        ]);
        if ($result === false) {
            if ($nextId) {
                $this->deleteValue($nextId);
            }
            return false;
        }
        return $setKey;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function delete($key)
    {
        return $this->deleteValue($key);
    }

    /**
     * @param $key
     * @return bool
     */
    private function deleteValue($key)
    {
        $column = $column = $this->tableInstance->get($key);
        if ($column) {
            $nextId = $column['nextId'];
            unset($column);
            $nextId && $this->deleteValue($nextId);
        }
        return $this->tableInstance->del($key);
    }

    /**
     * @return bool|void
     */
    public function clear()
    {
        $table = $this->tableInstance;
        foreach ($table as $key => $column) {
            $this->tableInstance->del($key);
        }
    }

    /**
     * @param iterable $keys
     * @param null $default
     * @return array|iterable
     */
    public function getMultiple($keys, $default = null)
    {
        $values = $this->getValues(array_values($keys));
        $results = [];
        foreach ($keys as $key => $newKey) {
            $results[$key] = false;
            if (isset($values[$newKey])) {
                if ($this->serializer === null) {
                    $results[$key] = Serialize::unpack($values[$newKey]);
                } else {
                    $results[$key] = $this->serializer->decode($values[$newKey]);
                }
            }
        }

        return $results;
    }

    /**
     * @param iterable $values
     * @param null $ttl
     * @return array|bool
     */
    public function setMultiple($values, $ttl = null)
    {
        $data = [];
        foreach ($items as $key => $value) {
            if ($this->serializer === null) {
                $value = Serialize::pack($value);
            } else {
                $value = $this->serializer->encode($value);
            }
            $data[$key] = $value;
        }

        return $this->setValues($data, $ttl);
    }

    /**
     * @param $data
     * @param $duration
     * @return array
     */
    private function setValues($data, $duration)
    {
        $failedKeys = [];
        foreach ($data as $key => $value) {
            if ($this->setValue($key, $value, $duration) === false) {
                $failedKeys[] = $key;
            }
        }

        return $failedKeys;
    }

    /**
     * @param iterable $keys
     * @return bool|void
     */
    public function deleteMultiple($keys)
    {
        $failedKeys = [];
        foreach ($data as $key => $value) {
            if ($this->deleteValue($key) === false) {
                $failedKeys[] = $key;
            }
        }

        return $failedKeys;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function has($key)
    {
        $value = $this->getValue($key);

        return $value !== false;
    }

    /**
     * @param bool $force
     */
    private function gc($force = false)
    {
        if ($force || mt_rand(0, 1000000) < $this->gcProbability) {
            App::info("TableCache GC begin");
            $i = 100000;
            $table = $this->tableInstance;
            foreach ($table as $key => $column) {
                if ($column['expire'] < time() || true) {
                    $this->deleteValue($key);
                }
                $i--;
                if ($i <= 0) {
                    \Swoole\Coroutine::sleep($this->gcSleep);
                    $i = 100000;
                }
            }
            App::info("TableCache GC end.");
        }
    }
}