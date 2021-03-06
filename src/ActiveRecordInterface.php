<?php
declare(strict_types=1);
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace Rabbit\ActiveRecord;

use Rabbit\Base\Core\StaticInstanceInterface;
use Rabbit\Pool\ConnectionInterface;

/**
 * ActiveRecordInterface.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @author Carsten Brandt <mail@cebe.cc>
 * @since 2.0
 */
interface ActiveRecordInterface extends StaticInstanceInterface
{
    /**
     * Returns the primary key **name(s)** for this AR class.
     *
     * Note that an array should be returned even when the record only has a single primary key.
     *
     * For the primary key **value** see [[getPrimaryKey()]] instead.
     *
     * @return string[] the primary key name(s) for this AR class.
     */
    public static function primaryKey(): array;

    /**
     * Returns the list of all attribute names of the record.
     * @return array list of attribute names.
     */
    public function attributes(): array;

    /**
     * Returns the named attribute value.
     * If this record is the result of a query and the attribute is not loaded,
     * `null` will be returned.
     * @param string $name the attribute name
     * @return mixed the attribute value. `null` if the attribute is not set or does not exist.
     * @see hasAttribute()
     */
    public function getAttribute(string $name);

    /**
     * Sets the named attribute value.
     * @param string $name the attribute name.
     * @param mixed $value the attribute value.
     * @see hasAttribute()
     */
    public function setAttribute(string $name, $value): void;

    /**
     * Returns a value indicating whether the record has an attribute with the specified name.
     * @param string $name the name of the attribute
     * @return bool whether the record has an attribute with the specified name.
     */
    public function hasAttribute(string $name): bool;

    /**
     * @param bool $asArray
     * @return array|null
     */
    public function getPrimaryKey(): ?array;

    /**
     * @return array|null
     */
    public function getOldPrimaryKey(): ?array;

    /**
     * Returns a value indicating whether the given set of attributes represents the primary key for this model.
     * @param array $keys the set of attributes to check
     * @return bool whether the given set of attributes represents the primary key for this model
     */
    public static function isPrimaryKey(array $keys): bool;

    /**
     * Creates an [[ActiveQueryInterface]] instance for query purpose.
     *
     * The returned [[ActiveQueryInterface]] instance can be further customized by calling
     * methods defined in [[ActiveQueryInterface]] before `one()` or `all()` is called to return
     * populated ActiveRecord instances. For example,
     *
     * ```php
     * // find the customer whose ID is 1
     * $customer = Customer::find()->where(['id' => 1])->one();
     *
     * // find all active customers and order them by their age:
     * $customers = Customer::find()
     *     ->where(['status' => 1])
     *     ->orderBy('age')
     *     ->all();
     * ```
     *
     * This method is also called by [[BaseActiveRecord::hasOne()]] and [[BaseActiveRecord::hasMany()]] to
     * create a relational query.
     *
     * You may override this method to return a customized query. For example,
     *
     * ```php
     * class Customer extends ActiveRecord
     * {
     *     public static function find()
     *     {
     *         // use CustomerQuery instead of the default ActiveQuery
     *         return new CustomerQuery(get_called_class());
     *     }
     * }
     * ```
     *
     * The following code shows how to apply a default condition for all queries:
     *
     * ```php
     * class Customer extends ActiveRecord
     * {
     *     public static function find()
     *     {
     *         return parent::find()->where(['deleted' => false]);
     *     }
     * }
     *
     * // Use andWhere()/orWhere() to apply the default condition
     * // SELECT FROM customer WHERE `deleted`=:deleted AND age>30
     * $customers = Customer::find()->andWhere('age>30')->all();
     *
     * // Use where() to ignore the default condition
     * // SELECT FROM customer WHERE age>30
     * $customers = Customer::find()->where('age>30')->all();
     *
     * @return ActiveQueryInterface the newly created [[ActiveQueryInterface]] instance.
     */
    public static function find(): ActiveQueryInterface;

    /**
     * Returns a single active record model instance by a primary key or an array of column values.
     *
     * The method accepts:
     *
     *  - a scalar value (integer or string): query by a single primary key value and return the
     *    corresponding record (or `null` if not found).
     *  - a non-associative array: query by a list of primary key values and return the
     *    first record (or `null` if not found).
     *  - an associative array of name-value pairs: query by a set of attribute values and return a single record
     *    matching all of them (or `null` if not found). Note that `['id' => 1, 2]` is treated as a non-associative array.
     *    Column names are limited to current records table columns for SQL DBMS, or filtered otherwise to be limited to simple filter conditions.
     *
     * That this method will automatically call the `one()` method and return an [[ActiveRecordInterface|ActiveRecord]]
     * instance.
     *
     * > Note: As this is a short-hand method only, using more complex conditions, like ['!=', 'id', 1] will not work.
     * > If you need to specify more complex conditions, use [[find()]] in combination with [[ActiveQuery::where()|where()]] instead.
     *
     * See the following code for usage examples:
     *
     * ```php
     * // find a single customer whose primary key value is 10
     * $customer = Customer::findOne(10);
     *
     * // the above code is equivalent to:
     * $customer = Customer::find()->where(['id' => 10])->one();
     *
     * // find the customers whose primary key value is 10, 11 or 12.
     * $customers = Customer::findOne([10, 11, 12]);
     *
     * // the above code is equivalent to:
     * $customers = Customer::find()->where(['id' => [10, 11, 12]])->one();
     *
     * // find the first customer whose age is 30 and whose status is 1
     * $customer = Customer::findOne(['age' => 30, 'status' => 1]);
     *
     * // the above code is equivalent to:
     * $customer = Customer::find()->where(['age' => 30, 'status' => 1])->one();
     * ```
     *
     * If you need to pass user input to this method, make sure the input value is scalar or in case of
     * array condition, make sure the array structure can not be changed from the outside:
     *
     * ```php
     * // rabbit\web\Controller ensures that $id is scalar
     * public function actionView($id)
     * {
     *     $model = Post::findOne($id);
     *     // ...
     * }
     *
     * // explicitly specifying the colum to search, passing a scalar or array here will always result in finding a single record
     * $model = Post::findOne(['id' => Yii::$app->request->get('id')]);
     *
     * // do NOT use the following code! it is possible to inject an array condition to filter by arbitrary column values!
     * $model = Post::findOne(Yii::$app->request->get('id'));
     * ```
     *
     * @param mixed $condition primary key value or a set of column values
     * @return static ActiveRecord instance matching the condition, or `null` if nothing matches.
     */
    public static function findOne($condition);

    /**
     * Returns a list of active record models that match the specified primary key value(s) or a set of column values.
     *
     * The method accepts:
     *
     *  - a scalar value (integer or string): query by a single primary key value and return an array containing the
     *    corresponding record (or an empty array if not found).
     *  - a non-associative array: query by a list of primary key values and return the
     *    corresponding records (or an empty array if none was found).
     *    Note that an empty condition will result in an empty result as it will be interpreted as a search for
     *    primary keys and not an empty `WHERE` condition.
     *  - an associative array of name-value pairs: query by a set of attribute values and return an array of records
     *    matching all of them (or an empty array if none was found). Note that `['id' => 1, 2]` is treated as
     *    a non-associative array.
     *    Column names are limited to current records table columns for SQL DBMS, or filtered otherwise to be limted to simple filter conditions.
     *
     * This method will automatically call the `all()` method and return an array of [[ActiveRecordInterface|ActiveRecord]]
     * instances.
     *
     * > Note: As this is a short-hand method only, using more complex conditions, like ['!=', 'id', 1] will not work.
     * > If you need to specify more complex conditions, use [[find()]] in combination with [[ActiveQuery::where()|where()]] instead.
     *
     * See the following code for usage examples:
     *
     * ```php
     * // find the customers whose primary key value is 10
     * $customers = Customer::findAll(10);
     *
     * // the above code is equivalent to:
     * $customers = Customer::find()->where(['id' => 10])->all();
     *
     * // find the customers whose primary key value is 10, 11 or 12.
     * $customers = Customer::findAll([10, 11, 12]);
     *
     * // the above code is equivalent to:
     * $customers = Customer::find()->where(['id' => [10, 11, 12]])->all();
     *
     * // find customers whose age is 30 and whose status is 1
     * $customers = Customer::findAll(['age' => 30, 'status' => 1]);
     *
     * // the above code is equivalent to:
     * $customers = Customer::find()->where(['age' => 30, 'status' => 1])->all();
     * ```
     *
     * If you need to pass user input to this method, make sure the input value is scalar or in case of
     * array condition, make sure the array structure can not be changed from the outside:
     *
     * ```php
     * // rabbit\web\Controller ensures that $id is scalar
     * public function actionView($id)
     * {
     *     $model = Post::findOne($id);
     *     // ...
     * }
     *
     * // explicitly specifying the colum to search, passing a scalar or array here will always result in finding a single record
     * $model = Post::findOne(['id' => Yii::$app->request->get('id')]);
     *
     * // do NOT use the following code! it is possible to inject an array condition to filter by arbitrary column values!
     * $model = Post::findOne(Yii::$app->request->get('id'));
     * ```
     *
     * @param mixed $condition primary key value or a set of column values
     * @return array an array of ActiveRecord instance, or an empty array if nothing matches.
     */
    public static function findAll($condition): array;

    /**
     * Updates records using the provided attribute values and conditions.
     *
     * For example, to change the status to be 1 for all customers whose status is 2:
     *
     * ```php
     * Customer::updateAll(['status' => 1], ['status' => '2']);
     * ```
     *
     * @param array $attributes attribute values (name-value pairs) to be saved for the record.
     * Unlike [[update()]] these are not going to be validated.
     * @param string|array $condition the condition that matches the records that should get updated.
     * Please refer to [[QueryInterface::where()]] on how to specify this parameter.
     * An empty condition will match all records.
     * @return int the number of rows updated
     */
    public static function updateAll(array $attributes, $condition = ''): int;

    /**
     * Deletes records using the provided conditions.
     * WARNING: If you do not specify any condition, this method will delete ALL rows in the table.
     *
     * For example, to delete all customers whose status is 3:
     *
     * ```php
     * Customer::deleteAll([status = 3]);
     * ```
     *
     * @param array $condition the condition that matches the records that should get deleted.
     * Please refer to [[QueryInterface::where()]] on how to specify this parameter.
     * An empty condition will match all records.
     * @return int the number of rows deleted
     */
    public static function deleteAll($condition = null): int;

    /**
     * Saves the current record.
     *
     * This method will call [[insert()]] when [[getIsNewRecord()|isNewRecord]] is true, or [[update()]]
     * when [[getIsNewRecord()|isNewRecord]] is false.
     *
     * For example, to save a customer record:
     *
     * ```php
     * $customer = new Customer; // or $customer = Customer::findOne($id);
     * $customer->name = $name;
     * $customer->email = $email;
     * $customer->save();
     * ```
     *
     * @param bool $runValidation whether to perform validation (calling [[\rabbit\base\Model::validate()|validate()]])
     * before saving the record. Defaults to `true`. If the validation fails, the record
     * will not be saved to the database and this method will return `false`.
     * @param array $attributeNames list of attribute names that need to be saved. Defaults to `null`,
     * meaning all attributes that are loaded from DB will be saved.
     * @return bool whether the saving succeeded (i.e. no validation errors occurred).
     */
    public function save(bool $runValidation = true, array $attributeNames = null): bool;

    /**
     * Inserts the record into the database using the attribute values of this record.
     *
     * Usage example:
     *
     * ```php
     * $customer = new Customer;
     * $customer->name = $name;
     * $customer->email = $email;
     * $customer->insert();
     * ```
     *
     * @param bool $runValidation whether to perform validation (calling [[\rabbit\base\Model::validate()|validate()]])
     * before saving the record. Defaults to `true`. If the validation fails, the record
     * will not be saved to the database and this method will return `false`.
     * @param array $attributes list of attributes that need to be saved. Defaults to `null`,
     * meaning all attributes that are loaded from DB will be saved.
     * @return bool whether the attributes are valid and the record is inserted successfully.
     */
    public function insert(bool $runValidation = true, array $attributes = null): bool;

    /**
     * Saves the changes to this active record into the database.
     *
     * Usage example:
     *
     * ```php
     * $customer = Customer::findOne($id);
     * $customer->name = $name;
     * $customer->email = $email;
     * $customer->update();
     * ```
     *
     * @param bool $runValidation whether to perform validation (calling [[\rabbit\base\Model::validate()|validate()]])
     * before saving the record. Defaults to `true`. If the validation fails, the record
     * will not be saved to the database and this method will return `false`.
     * @param array $attributeNames list of attributes that need to be saved. Defaults to `null`,
     * meaning all attributes that are loaded from DB will be saved.
     * @return int the number of rows affected, or `false` if validation fails
     * or updating process is stopped for other reasons.
     * Note that it is possible that the number of rows affected is 0, even though the
     * update execution is successful.
     */
    public function update(bool $runValidation = true, array $attributeNames = null): int;

    /**
     * Deletes the record from the database.
     *
     * @return int the number of rows deleted, or `false` if the deletion is unsuccessful for some reason.
     * Note that it is possible that the number of rows deleted is 0, even though the deletion execution is successful.
     */
    public function delete(): int;

    /**
     * Returns a value indicating whether the current record is new (not saved in the database).
     * @return bool whether the record is new and should be inserted when calling [[save()]].
     */
    public function getIsNewRecord(): bool;

    /**
     * Returns a value indicating whether the given active record is the same as the current one.
     * Two [[getIsNewRecord()|new]] records are considered to be not equal.
     * @param static $record record to compare to
     * @return bool whether the two active records refer to the same row in the same database table.
     */
    public function equals(ActiveRecordInterface $record): bool;

    /**
     * Returns the relation object with the specified name.
     * A relation is defined by a getter method which returns an object implementing the [[ActiveQueryInterface]]
     * (normally this would be a relational [[ActiveQuery]] object).
     * It can be declared in either the ActiveRecord class itself or one of its behaviors.
     * @param string $name the relation name, e.g. `orders` for a relation defined via `getOrders()` method (case-sensitive).
     * @param bool $throwException whether to throw exception if the relation does not exist.
     * @return ActiveQueryInterface the relational query object
     */
    public function getRelation($name, $throwException = true): ?ActiveQueryInterface;

    /**
     * Populates the named relation with the related records.
     * Note that this method does not check if the relation exists or not.
     * @param string $name the relation name, e.g. `orders` for a relation defined via `getOrders()` method (case-sensitive).
     * @param ActiveRecordInterface|array|null $records the related records to be populated into the relation.
     * @since 2.0.8
     */
    public function populateRelation(string $name, $records): void;

    /**
     * Establishes the relationship between two records.
     *
     * The relationship is established by setting the foreign key value(s) in one record
     * to be the corresponding primary key value(s) in the other record.
     * The record with the foreign key will be saved into database without performing validation.
     *
     * If the relationship involves a junction table, a new row will be inserted into the
     * junction table which contains the primary key values from both records.
     *
     * This method requires that the primary key value is not `null`.
     *
     * @param string $name the case sensitive name of the relationship, e.g. `orders` for a relation defined via `getOrders()` method.
     * @param ActiveRecordInterface $model the record to be linked with the current one.
     * @param array $extraColumns additional column values to be saved into the junction table.
     * This parameter is only meaningful for a relationship involving a junction table
     * (i.e., a relation set with [[ActiveQueryInterface::via()]]).
     */
    public function link(string $name, ActiveRecordInterface $model, array $extraColumns = []): void;

    /**
     * Destroys the relationship between two records.
     *
     * The record with the foreign key of the relationship will be deleted if `$delete` is true.
     * Otherwise, the foreign key will be set `null` and the record will be saved without validation.
     *
     * @param string $name the case sensitive name of the relationship, e.g. `orders` for a relation defined via `getOrders()` method.
     * @param static $model the model to be unlinked from the current one.
     * @param bool $delete whether to delete the model that contains the foreign key.
     * If false, the model's foreign key will be set `null` and saved.
     * If true, the model containing the foreign key will be deleted.
     */
    public function unlink(string $name, ActiveRecordInterface $model, bool $delete = false): void;

    /**
     * Returns the connection used by this AR class.
     * @return mixed the database connection used by this AR class.
     */
    public static function getDb(): ConnectionInterface;
}
