<?php
/**
 * @package Permafrost/DynamicRelations
 * @author Patrick Organ <trick.developer@gmail.com>
 * @version 1.1.0
 * @license MIT
 */
 
namespace Permafrost\DynamicRelations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\RelationNotFoundException;

class DynamicModel extends Model
{

    /**
     * The default attribute name to use for associating dynamic relations
     * with the model. If set to null, it will use the name of the
     * current model + '_id'.
     *
     * @var string|null
     */
    protected static $dynamicRelationDefaultKey   = null;//'{{modelName}}_id';

    /**
     * The default relationship method to use for dynamic relations.
     *
     * @var string
     */
    protected static $dynamicRelationDefaultType  = 'hasMany';

    /**
     * The default namespace to use when determining model names for dynamic
     * relations.
     *
     * @var string
     */
    protected static $dynamicRelationDefaultModelNamespace  = 'App\\';

    /**
     * Name-value pairs that define what namespaces to use for the dynamic
     * relations specific to this model. If the dynamic relation is not
     * defined here, the default model namespace will be used.
     * relation_name => model_name
     *
     * @var array
     */
    protected static $dynamicRelationModelMap   = [];

    /**
     * Name-value pairs that define what attributes to use for the dynamic
     * relations specific to this model. If the dynamic relation is not
     * in the array, the default key name will be used.
     * relation_name => attribute_name
     *
     * @var array
     */
    protected static $dynamicRelationKeyMap   = [];

    /**
     * Name-value pairs that define what relationship type should be used for
     * specific dynamic relations. If the dynamic relation is not defined
     * here, the default type will be used. Use relation method names as
     * values, i.e. 'hasMany', 'belongsTo'.
     * relation_name => relationship_type
     *
     * @var array
     */
    protected static $dynamicRelationTypeMap  = [];


    /**
     * Name-value pairs that the dynamic relations should be converted to, if any.
     * For example, use the dynamic relation name languages, but really use 'user_languages':
     * 'lanuages'=>'user_languages',
     * @var array
     */
    protected static $dynamicRelationNameToRelationMap = [];

    /**
     * The names of the relationships that should be treated as dynamic
     * relations.
     *
     * @var array
     */
    protected static $dynamicRelations = [];

    /**
     * Create a new Eloquent model instance (inherited).  Also initializes the
     * default dynamic relation key. If set to null, the default key is set
     * to classname + "_id".
     *
     * @param  array  $attributes
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        if (is_null(static::$dynamicRelationDefaultKey)) {
            static::$dynamicRelationDefaultKey = snake_case(class_basename(static::class)) . '_id';
        }
    }

    protected static function getDynamicRelationKey($name)
    {
      return (isset(static::$dynamicRelationKeyMap[$name]) ?
        static::$dynamicRelationKeyMap[$name] : static::$dynamicRelationDefaultKey);
    }

    protected static function getDynamicRelationType($name)
    {
      return (isset(static::$dynamicRelationTypeMap[$name]) ?
                    static::$dynamicRelationTypeMap[$name] :
                    static::$dynamicRelationDefaultType);
    }

    /**
     * Determine if the given name is being handled as a dynamic relation.
     *
     * @param string $name
     * @return bool
     */
    public static function isDynamicRelation($name)
    {
      return in_array($name, static::$dynamicRelations);
    }

    protected static function translateDynamicRelationName($name)
    {
      if (in_array($name, self::$dynamicRelationNameToRelationMap)) {
        return self::$dynamicRelationNameToRelationMap[$name];
      }
      return $name;
    }

    /**
     * The proxy method used to determine the relationships for dynamic relations.
     * Calls to this with the name of a dynamic relation are equal to calling a
     * normal relation method, such as a method that returns "hasOne(class)".
     * In the unlikely event that the proxy is called with a name that is
     * not a dynamic relation, try to handle it as a normal relation.
     *
     * @param string $name
     * @param array $parameters
     *
     * @return \Illuminate\Database\Eloquent\Relations\Relation
     *
     * @throws \RelationNotFoundException
     */
    protected function dynamicRelationProxy($name, $parameters = [])
    {
        $originalName = $name;
        $name = $this->translateDynamicRelationName($name);

        if ($this->isDynamicRelation($name)) {
            //if (isset(static::$dynamicRelationNameToRelationMap[$name]))
              //$name = static::$dynamicRelationNameToRelationMap[$name];

            $model  = static::getDynamicRelationModelName($name);
            $key    = static::getDynamicRelationKey($name);
            $type   = static::getDynamicRelationType($originalName);

            return $this->$type($model, $key);
        }

        //The specified name is not a dynamic relation, but try to handle it as a normal
        //relation before failing. You should not call getRelationValue() from here, as
        //it is remotely possible (but unlikely) that a circular call loop can occur.
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        if (method_exists($this, $originalName)) {
          return $this->getRelationshipFromMethod($originalName, false);
        }

        throw new \RelationNotFoundException("dynamicRelationProxy failed: relation '$name' not found");
    }

    /**
     * Determine the name of the model to use for a dynamic relation.  If the
     * dynamic relation is defined in the model map, then that value is used,
     * otherwise the default model namespace is used and name is converted
     * to studly case and singular form for use as the model name.
     *
     * @param string $name
     *
     * @return string
     */
    protected static function getDynamicRelationModelName($name)
    {
        if (array_key_exists($name, static::$dynamicRelationModelMap)) {
            return static::$dynamicRelationModelMap[$name];
        }

        $namespace = static::$dynamicRelationDefaultModelNamespace;
        $model = studly_case(str_singular($name));

        if (strlen($namespace) > 0) {
            $namespace = str_finish($namespace, '\\');
        }

        return $namespace . $model;
    }

    /**
     * Handle dynamic method calls into the model.  Also handles dynamic relation
     * calls via the proxy.
     *
     * @param  string  $method
     * @param  array  $parameters
     * @return mixed
     */
    public function __call($name, $parameters)
    {
      //proxy certian static methods to allow calling them as a normal method
      if (in_array($name, ['isDynamicRelation']))
        return static::$name($parameters);

      if ($this->isDynamicRelation($name)) {
        return $this->dynamicRelationProxy($name);
      }
      return parent::__call($name, $parameters);
    }

    /**
     * Dynamically retrieve attributes on the model.  Also handles dynamic relations
     * accessed as properties via the proxy.
     *
     * @param  string  $key
     * @return mixed
     */
    public function __get($name)
    {
      if ($this->isDynamicRelation($name)) {
        return $this->getRelationValue($name);
      }
      return parent::__get($name);
    }

    /**
     * Overrides the default Eloquent Model method, to allow use of a proxy method
     * for determining relationships.
     *
     * @param string $key
     * @return mixed
     */
    public function getRelationValue($key)
    {
        // If the key already exists in the relationships array, it just means the
        // relationship has already been loaded, so we'll just return it out of
        // here because there is no need to query within the relations twice.
        if ($this->relationLoaded($key)) {
            return $this->relations[$key];
        }

        //If the key is a dynamic relation, determine the relationship using the proxy
        //method, otherwise use a method named $key, if it exists.
        if ($use_proxy = $this->isDynamicRelation($key) || method_exists($this, $key)) {
          return $this->getRelationshipFromMethod($key, $use_proxy);
        }
    }

    /**
     * Overrides defaukt Eloquent Model method to allow for use of a proxy method for
     * determining relationships.  If $use_proxy==true, use this->proxy($method)
     * instead of this->$method() for determining the relationship.
     *
     * @param string $method
     * @param bool $use_proxy
     * @return mixed
     *
     * @throws \LogicException
     */
    protected function getRelationshipFromMethod($method, $use_proxy = false)
    {
        $relations = ($use_proxy ? $this->dynamicRelationProxy($method) : $this->$method());

        if (! $relations instanceof Relation) {
            throw new \LogicException('Relationship method must return an object of type '
                .'Illuminate\Database\Eloquent\Relations\Relation');
        }

        $this->setRelation($method, $results = $relations->getResults());

        return $results;
    }

}