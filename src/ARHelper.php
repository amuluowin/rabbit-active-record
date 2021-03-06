<?php

declare(strict_types=1);

namespace Rabbit\ActiveRecord;

use Rabbit\Base\Core\Context;
use Rabbit\DB\Query;
use Rabbit\DB\DBHelper;
use Rabbit\DB\Exception;
use Rabbit\DB\Expression;
use Rabbit\DB\JsonExpression;
use Rabbit\Base\Helper\JsonHelper;
use Rabbit\Base\Core\UserException;
use Rabbit\Base\Exception\InvalidArgumentException;
use Rabbit\Base\Helper\ArrayHelper;
use Rabbit\Pool\ConnectionInterface;

class ARHelper
{
    const INSERT_REPLACE = 'REPLACE';
    const INSERT_DEFAULT = 'INSERT';
    const INSERT_IGNORE = 'INSERT IGNORE';

    public static function typeInsert(BaseActiveRecord $model, array $arrayColumns, string $insertType = self::INSERT_DEFAULT): int
    {
        if (empty($arrayColumns)) {
            return 0;
        }
        $conn = $model::getDb();
        $sql = '';
        $params = [];
        $i = 0;
        if (!ArrayHelper::isIndexed($arrayColumns)) {
            $arrayColumns = [$arrayColumns];
        }
        $keys = $model::primaryKey();

        $schema = $conn->getSchema();
        $tableSchema = $schema->getTableSchema($model::tableName());
        $columnSchemas = $tableSchema !== null ? $tableSchema->columns : [];

        foreach ($arrayColumns as $item) {
            $table = clone $model;
            $table->load($item, '');
            $names = array();
            $placeholders = array();
            $table->isNewRecord = false;
            if (!$table->validate()) {
                throw new UserException(implode(PHP_EOL, $table->getFirstErrors()));
            }
            $tableArray = $table->toArray();
            if ($keys) {
                foreach ($keys as $key) {
                    if (($item[$key] ?? false) && $tableArray[$key] === null) {
                        $tableArray[$key] = $item[$key];
                    }
                }
            }
            ksort($tableArray);
            foreach ($tableArray as $name => $value) {
                $value = $item[$name];
                if (!$i) {
                    $names[] = $conn->quoteColumnName($name);
                }
                $value = ($columnSchemas[$name] ?? false) ? $columnSchemas[$name]->dbTypecast($value) : $value;
                if ($value instanceof Expression) {
                    $placeholders[] = $value->expression;
                    foreach ($value->params as $n => $v) {
                        $params[$n] = $v;
                    }
                } elseif ($value instanceof JsonExpression) {
                    $placeholders[] = '?';
                    $params[] = is_string($value->getValue()) ? $value->getValue() : JsonHelper::encode($value->getValue());
                } else {
                    $placeholders[] = '?';
                    $params[] = $value;
                }
            }
            if (!$i) {
                $sql = $insertType . ' INTO ' . $conn->quoteTableName($table::tableName())
                    . ' (' . implode(', ', $names) . ') VALUES ('
                    . implode(', ', $placeholders) . ')';
            } else {
                $sql .= ',(' . implode(', ', $placeholders) . ')';
            }
            $i++;
        }
        return $conn->createCommand($sql, $params)->execute();
    }

    public static function saveSeveral(BaseActiveRecord $model, array $arrayColumns, bool $withUpdate = true): int
    {
        if (empty($arrayColumns)) {
            return 0;
        }
        $conn = $model::getDb();
        $sql = '';
        $params = [];
        $i = 0;
        if (!ArrayHelper::isIndexed($arrayColumns)) {
            $arrayColumns = [$arrayColumns];
        }
        $keys = $model::primaryKey();

        $schema = $conn->getSchema();
        $tableSchema = $schema->getTableSchema($model::tableName());
        $columnSchemas = $tableSchema !== null ? $tableSchema->columns : [];

        foreach ($arrayColumns as $item) {
            $table = clone $model;
            $table->load($item, '');
            if (!$table->validate()) {
                throw new UserException(implode(PHP_EOL, $table->getFirstErrors()));
            }
            $tableArray = $table->toArray();
            $item = array_merge($item, $tableArray);
            //关联模型
            foreach ($table->getRelations() as $child => [$key, $val, $delete]) {
                if ($item[$key] ?? false) {
                    $child_model = new $child();
                    if (!ArrayHelper::isIndexed($item[$key])) {
                        $item[$key] = [$item[$key]];
                    }
                    foreach ($val as $c_attr => $p_attr) {
                        foreach ($item[$key] as &$param) {
                            $param[$c_attr] = $table->{$p_attr};
                        }
                    }
                    if ($delete) {
                        if (is_array($delete)) {
                            self::delete($child_model, $delete);
                        } elseif (is_callable($delete)) {
                            call_user_func($delete, $child_model, $item[$key]);
                        }
                    }
                    if (self::saveSeveral($child_model, $item[$key]) === false) {
                        return 0;
                    }
                }
            }
            $names = array();
            $placeholders = array();
            $table->isNewRecord = false;
            if ($keys) {
                foreach ($keys as $key) {
                    if (($item[$key] ?? false) && $tableArray[$key] === null) {
                        $tableArray[$key] = $item[$key];
                    }
                }
            }
            ksort($tableArray);
            foreach ($tableArray as $name => $value) {
                $value = $item[$name];
                if (!$i) {
                    $names[] = $conn->quoteColumnName($name);
                    $withUpdate && !in_array($name, $keys ?? []) && ($updates[] = $conn->quoteColumnName($name) . "=values(" . $conn->quoteColumnName($name) . ")");
                }
                $value = ($columnSchemas[$name] ?? false) ? $columnSchemas[$name]->dbTypecast($value) : $value;
                if ($value instanceof Expression) {
                    $placeholders[] = $value->expression;
                    foreach ($value->params as $n => $v) {
                        $params[$n] = $v;
                    }
                } elseif ($value instanceof JsonExpression) {
                    $placeholders[] = '?';
                    $params[] = is_string($value->getValue()) ? $value->getValue() : JsonHelper::encode($value->getValue());
                } else {
                    $placeholders[] = '?';
                    $params[] = $value;
                }
            }
            if (!$i) {
                $sql = 'INSERT INTO ' . $conn->quoteTableName($table::tableName())
                    . ' (' . implode(', ', $names) . ') VALUES ('
                    . implode(', ', $placeholders) . ')';
            } else {
                $sql .= ',(' . implode(', ', $placeholders) . ')';
            }
            $i++;
        }
        $withUpdate && $updates && $sql .= " on duplicate key update " . implode(', ', $updates);
        return $conn->createCommand($sql, $params)->execute();
    }

    public static function updateSeveral(BaseActiveRecord $model, array $arrayColumns, array $when = null): int
    {
        if (empty($arrayColumns)) {
            return 0;
        }
        $updateSql = '';
        if (!ArrayHelper::isIndexed($arrayColumns)) {
            $arrayColumns = [$arrayColumns];
        }
        $firstRow = current($arrayColumns);
        $updateColumn = array_keys($firstRow);
        $conn = $model::getDb();
        $schema = $conn->getSchema();
        $tableSchema = $schema->getTableSchema($model::tableName());
        $columnSchemas = $tableSchema !== null ? $tableSchema->columns : [];
        $referenceColumns = $when ?? $model::primaryKey();
        foreach ($referenceColumns as $column) {
            unset($updateColumn[array_search($column, $updateColumn)]);
        }
        $updateSql = "UPDATE " .  $model::tableName() . " SET ";
        $sets = [];
        $bindings = [];
        $wheres = [];
        foreach ($updateColumn as $uColumn) {
            if ($columnSchemas[$uColumn] ?? false) {
                $setSql = "`" . $uColumn . "` = CASE ";
                foreach ($arrayColumns as $data) {
                    $refSql = '';
                    foreach ($referenceColumns as $i => $ref) {
                        if (!($data[$ref] ?? false)) {
                            throw new InvalidArgumentException("data has no filed: $ref!" . PHP_EOL . json_encode($data));
                        }
                        $refSql .= " `" . $ref . "` = ? and";
                        $value = ($columnSchemas[$ref] ?? false) ? $columnSchemas[$ref]->dbTypecast($data[$ref]) : $data[$ref];
                        if (!is_string($value) && !is_int($value) && !is_float($value)) {
                            throw new InvalidArgumentException("$ref value is not support!" . PHP_EOL . json_encode($data));
                        }
                        $bindings[] = $value;
                        if (!($wheres[$i] ?? false) || !in_array($value, $wheres[$i])) {
                            $wheres[$i][] = $value;
                        }
                    }
                    $refSql = rtrim($refSql, 'and');
                    $value = ($columnSchemas[$uColumn] ?? false) ? $columnSchemas[$uColumn]->dbTypecast($data[$uColumn]) : $data[$uColumn];
                    if ($value instanceof JsonExpression) {
                        $bindings[] = is_string($value->getValue()) ? $value->getValue() : JsonHelper::encode($value);
                    } elseif ($value instanceof Expression) {
                        $setSql .= "WHEN $refSql THEN {$value->expression} ";
                        continue;
                    } else {
                        $bindings[] = $value;
                    }
                    $setSql .= "WHEN $refSql THEN ? ";
                }
                $setSql .= "ELSE `" . $uColumn . "` END ";
                $sets[] = $setSql;
            }
        }
        $updateSql .= implode(', ', $sets);
        $updateSql = rtrim($updateSql, ", ") . " where (" . implode(',', $referenceColumns) . ") in (";

        for ($i = 0; $i < count(reset($wheres)); $i++) {
            $pal = [];
            $tmp = array_column($wheres, $i);
            foreach ($tmp as $v) {
                $pal[] = '?';
                $bindings[] = $v;
            }
            $updateSql .= '(' . implode(',', $pal) . '),';
        }
        $updateSql = rtrim($updateSql, ',');
        $updateSql .= ')';
        return $conn->createCommand($updateSql, $bindings)->execute();
    }

    public static function deleteSeveral(BaseActiveRecord $model, array $arrayColumns): int
    {
        if (empty($arrayColumns)) {
            return 0;
        }
        $result = false;
        $keys = $model::primaryKey();
        $condition = [];
        if (ArrayHelper::isAssociative($arrayColumns)) {
            $arrayColumns = [$arrayColumns];
        }
        foreach ($arrayColumns as $item) {
            $model->load($item, '');
            $model->isNewRecord = false;
            foreach ($model->getRelations() as $child => [$key]) {
                if ($item[$key] ?? false) {
                    $child_model = new $child();
                    if (self::deleteSeveral($child_model, $item[$key]) === 0) {
                        return 0;
                    }
                }
            }
            if ($keys) {
                foreach ($keys as $key) {
                    if ($item[$key] ?? false) {
                        $condition[$key][] = $item[$key];
                    }
                }
            }
        }
        if ($condition) {
            $result = $model::deleteAll($condition);
        }
        return (int)$result;
    }

    public static function create(BaseActiveRecord $model, array $body, bool $batch = true): array
    {
        if (!ArrayHelper::isIndexed($body)) {
            $body = [$body];
        }
        if (!$batch) {
            $result = [];
            foreach ($body as $params) {
                $res = self::createModel(clone $model, $params);
                $result[] = $res;
            }
        } else {
            $result = self::saveSeveral($model, $body, false);
        }
        return is_array($result) ? $result : [$result];
    }

    public static function update(BaseActiveRecord $model, array $body, bool $onlyUpdate = false, array $when = null, bool $batch = true): array
    {
        if (($body['edit'] ?? false) && $body['edit']) {
            $result = $model->updateAll($body['edit'], DBHelper::Search((new Query()),  ArrayHelper::getValue($body, 'where', []))->where);
            if ($result === false) {
                throw new Exception('Failed to update the object for unknown reason.');
            }
        } else {
            if (!ArrayHelper::isIndexed($body)) {
                $body = [$body];
            }
            if ($onlyUpdate) {
                $result = self::updateSeveral($model, $body, $when);
            } elseif (!$batch) {
                $result = [];
                $exists = self::findExists($model, $body);
                foreach ($body as $params) {
                    $res = self::updateModel(clone $model, $params, self::checkExist($params, $exists, $model::primaryKey()));
                    $result[] = $res;
                }
            } else {
                $result = self::saveSeveral($model, $body);
            }
        }
        return is_array($result) ? $result : [$result];
    }

    public static function delete(BaseActiveRecord $model, array $body): int
    {
        if (ArrayHelper::isIndexed($body)) {
            return self::deleteSeveral($model, $body);
        }
        $keys = array_keys($body);
        if (array_intersect($keys, $model::primaryKey())) {
            $conditions = [];
            foreach ($model::primaryKey() as $key) {
                if ($body[$key] ?? false) {
                    $conditions[$key] = $body[$key];
                }
            }
            if (empty($conditions)) {
                return 0;
            }
            foreach ($model->getRelations() as $child => [$key]) {
                if ($body[$key] ?? false) {
                    $child_model = new $child();
                    if ($child_model::deleteAll($body[$key]) === 0) {
                        return 0;
                    }
                }
            }
            return $model::deleteAll($conditions);
        }
        foreach ($keys as $key) {
            if (str_contains(strtolower($key), 'where')) {
                return $model::deleteAll(DBHelper::Search((new Query()), $body)->where);
            }
        }
        return 0;
    }

    private static function createModel(BaseActiveRecord $model, array $body): array
    {
        $model->load($body, '');
        if ($model->save()) {
            $result = self::insertRealation($model, $body);
        } elseif (!$model->hasErrors()) {
            throw new Exception('Failed to create the object for unknown reason.');
        } else {
            throw new Exception(implode(PHP_EOL, $model->getFirstErrors()));
        }
        return $result;
    }

    private static function insertRealation(BaseActiveRecord $model, array $body): array
    {
        $result = [];
        //关联模型
        foreach ($model->getRelations() as $child => [$key, $val]) {
            if ($body[$key] ?? false) {
                if (ArrayHelper::isAssociative($body[$key])) {
                    $body[$key] = [$body[$key]];
                }
                foreach ($body[$key] as $params) {
                    if ($val) {
                        foreach ($val as $c_attr => $p_attr) {
                            $params[$c_attr] = $model->{$p_attr};
                        }
                    }
                    $child_model = new $child();
                    $res = self::createModel($child_model, $params);
                    $result[$key][] = $res;
                }
            }
        }
        $res = $model->toArray();
        foreach ($result as $key => $val) {
            $res[$key] = $val;
        }
        return $res;
    }

    private static function findExists(BaseActiveRecord $model, array $body, array $condition = []): array
    {
        $keys = $model::primaryKey();
        if (ArrayHelper::isAssociative($body)) {
            $body = [$body];
        }
        foreach ($keys as $key) {
            foreach ($body as $item) {
                if (array_key_exists($key, $item)) {
                    $condition[$key][] = $item[$key];
                }
            }
        }
        if ($condition !== [] && count($keys) === count($condition)) {
            return $model::find()->where($condition)->asArray()->all();
        }
        return [];
    }

    public static function updateModel(BaseActiveRecord $model, array $body, ?array $exist): array
    {
        $model->setOldAttributes($exist);
        $model->load($body, '');
        if ($model->save() === false && !$model->hasErrors()) {
            throw new Exception('Failed to update the object for unknown reason.');
        } else {
            $result = self::saveRealation($model, $body);
        }
        return $result;
    }

    protected static function saveRealation(BaseActiveRecord $model, array $body): array
    {
        $result = [];
        //关联模型
        foreach ($model->getRelations() as $child => [$key, $val, $delete]) {
            if ($body[$key] ?? false) {
                $child_model = new $child();
                if (($body['edit'] ?? false) && $body['edit']) {
                    $result[$key] = self::update($child_model, $body);
                } else {
                    if (ArrayHelper::isAssociative($body[$key])) {
                        $params = [$body[$key]];
                    } else {
                        $params = $body[$key];
                    }
                    if ($delete) {
                        if (is_array($delete)) {
                            self::delete($child_model, $delete);
                        } elseif (is_callable($delete)) {
                            call_user_func($delete, $child_model, $params);
                        }
                    }
                    $exists = self::findExists($child_model, $params);
                    foreach ($params as $param) {
                        if ($val) {
                            $child_model = new $child();
                            foreach ($val as $c_attr => $p_attr) {
                                $param[$c_attr] = $model->{$p_attr};
                            }
                            $result[$key][] = self::updateModel(
                                $child_model,
                                $param,
                                self::checkExist(
                                    $param,
                                    $exists,
                                    $child_model::primaryKey()
                                )
                            );
                        }
                    }
                }
            }
        }
        $res = $model->toArray();
        foreach ($result as $key => $val) {
            $res[$key] = $val;
        }
        return $res;
    }

    public static function checkExist(array $body, array $exists, array $conditions = []): ?array
    {
        if (!$exists) {
            return null;
        }
        $existCount = 0;
        foreach ($exists as $exist) {
            if (empty($conditions))
                foreach ($conditions as $key) {
                    if (($body[$key] ?? false) && $body[$key] === $exist[$key]) {
                        $existCount++;
                    }
                    if ($existCount === count($conditions)) {
                        return $exist;
                    }
                }
        }
        return null;
    }

    public static function getModel(string $table, string $db): BaseActiveRecord
    {
        return new class($table, $db) extends ActiveRecord
        {
            /**
             *  constructor.
             * @param string $tableName
             * @param string $dbName
             */
            public function __construct(string $tableName, string $dbName)
            {
                Context::set(md5(get_called_class() . 'tableName'), $tableName);
                Context::set(md5(get_called_class() . 'dbName'), $dbName);
            }

            /**
             * @return mixed|string
             */
            public static function tableName(): string
            {
                return Context::get(md5(get_called_class() . 'tableName'));
            }

            /**
             * @return ConnectionInterface
             */
            public static function getDb(): ConnectionInterface
            {
                return getDI('db')->getConnection(Context::get(md5(get_called_class() . 'dbName')));
            }
        };
    }
}
