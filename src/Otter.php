<?php

namespace Poowf\Otter;

use Closure;
use Exception;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Poowf\Otter\Http\Resources\OtterResource;

class Otter
{
    /**
     * The callback that should be used to authenticate Otter users.
     *
     * @var \Closure
     */
    public static $authUsing;

    public static $otterResourceNamespace = 'App\\Otter\\';

    /**
     * Determine if the given request can access the Otter dashboard.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return bool
     */
    public static function check($request)
    {
        return (static::$authUsing ?: function () {
            return app()->environment('local');
        })($request);
    }

    /**
     * Set the callback that should be used to authenticate Otter users.
     *
     * @param  \Closure  $callback
     * @return static
     */
    public static function auth(Closure $callback)
    {
        static::$authUsing = $callback;

        return new static;
    }

    /**
     * Retrieve the Names of the Otter Resources
     *
     * @param  \Closure  $callback
     * @return static
     */
    public static function getResourceNames($pretty = false)
    {
        $directory = app_path('Otter/');
        $files = File::files($directory);
        $names = new Collection;

        foreach($files as $file)
        {
            $path = $file->getPathname();
            $class = str_replace('.php', '', $path);
            $baseResourceName = basename($class);

            $resourceName = ($pretty) ? str_plural(preg_replace('/\B([A-Z])/', ' $1', $baseResourceName)) : self::getRouteNameFromClassName($baseResourceName);

            $names->push($resourceName);
        }

        return $names;
    }

    /**
     * Retrieve them class name from a route name
     *
     * user_addresses = UserAddress
     *
     * @return string
     */
    public static function getClassNameFromRouteName($routeName)
    {
        return str_replace(' ', '', str_singular(ucwords(str_replace('_', ' ', $routeName))));
    }

    /**
     * Get the route name from a class name
     *
     * UserAddress = user_addresses
     *
     * @return string
     */
    public static function getRouteNameFromClassName($className)
    {
        return str_plural(strtolower(preg_replace('/\B([A-Z])/', '_$1', $className)));
    }

    /**
     * Get the base class name from a fully qualified class name
     *
     * @return string
     */
    public static function getBaseClassName($className)
    {
        return preg_replace('@.*(\\\|\/)@', '', $className);
    }

    /**
     * Retrieve the users's gravatar photo
     *
     * @return string
     */
    public static function getGravatarLink($email)
    {
        $hash = md5(strtolower(trim($email)));

        return "//www.gravatar.com/avatar/$hash";
    }

    /**
     * Retrieve the model instance
     * This method checks if the object is an instance of the model and if it is not, 
     * it will take the object as the primary key of the model and retrieve it
     *
     * @param  \Closure  $callback
     * @return static
     */
    public static function getModelInstance($object, $modelName)
    {
       return ($object instanceof $modelName) ? $object  : $modelName::findOrFail($object);
    }

    /**
     * Retrieve all the fields that are not hidden in the resource collection
     *
     * @param  OtterResource $otterResource
     * @return array
     */
    public static function getAvailableFields($otterResource)
    {
        return array_diff_key($otterResource::fields(), array_flip($otterResource::hidden()));
    }

    /**
     * Retrieve all the fields that are relational in an OtterResource
     *
     * $otterRelationBaseClassName is the class name of the OtterResource
     * $otterRelationResource is the full class name of the OtterResource
     *
     * $relationshipModel is the full class name of the Eloquent Model defined in the OtterResource
     * $relationshipModelInstance is an instance of the Eloquent Model defined in the OtterResource
     * $relationshipType is the type of Eloquent Relation
     * $relationshipForeignKey is the type of Foreign Key used for the Eloquent Relation
     *
     * @param  OtterResource $otterResource
     * @return array
     */
    public static function getRelationalFields($otterResource, $modelObject = null)
    {
        $relationalDataArray = [];
        $otterResourceNamespace = self::$otterResourceNamespace;

        $modelInstance = ($modelObject) ? self::getModelInstance($modelObject, $otterResource::$model) : new $otterResource::$model;

        foreach($otterResource::relations() as $relationshipName => $otterRelationData)
        {
            $otterRelationBaseClassName = (is_array($otterRelationData)) ? $otterRelationData[0] : $otterRelationData;
            $otterRelationResource = $otterResourceNamespace . $otterRelationBaseClassName;

            $relationshipType = self::getBaseClassName(get_class($modelInstance->{$relationshipName}()));
            $relationshipModel = $otterRelationResource::$model;
            $relationshipModelInstance = new $relationshipModel;
            //Check if a foreign key is manually specified and if so, use the specified foreign key
            $relationshipForeignKey = (is_array($otterRelationData)) ? $otterRelationData[1] : $relationshipModelInstance->getForeignKey();

            $relation = [];
            $relation['relationshipName'] = $relationshipName;
            $relation['relationshipType'] = $relationshipType;
            $relation['relationshipModel'] = $relationshipModel;
            $relation['relationshipForeignKey'] = $relationshipForeignKey;
            $relation['relationshipId'] = 'null';

            $relation['resourceName'] = self::getRouteNameFromClassName($otterRelationBaseClassName);
            $relation['resourceTitle'] = $otterRelationResource::$title;
            $relation['resourceFields'] = self::getAvailableFields($otterRelationResource);
            $relation['resourceId'] = 'null';

            if($relationshipType === 'HasOne' || $relationshipType === 'BelongsTo')
            {
                $relation['relationshipId'] = ($modelInstance->{$relationshipForeignKey}) ? $modelInstance->{$relationshipForeignKey} : null;

                $relationModelInstance = $modelInstance->{$relationshipName};
                $relation['resourceId'] = ($relationModelInstance) ? $relationModelInstance->{$relationModelInstance->getRouteKeyName()} : null;
            }
            elseif($relationshipType === 'BelongsToMany')
            {
                $relation['relationshipId'] = ($modelInstance->{$relationshipName}) ? $modelInstance->{$relationshipName}()->allRelatedIds() : null;
            }

            $relationalDataArray[$relationshipName] = $relation;
        }

        return $relationalDataArray;
    }
    /**
     * Retrieve all the relational data in an OtterResource
     *
     * @param  OtterResource $otterResource
     * @return array
     */
    public static function getRelationalData($otterResource)
    {
        $relationalDataArray = [];
        $otterResourceNamespace = self::$otterResourceNamespace;

        foreach($otterResource::relations() as $relationshipName => $otterRelationData)
        {
            $otterRelationBaseClassName = (is_array($otterRelationData)) ? $otterRelationData[0] : $otterRelationData;
            $otterRelationResource = $otterResourceNamespace . $otterRelationBaseClassName;

            $relationalDataArray[$relationshipName] = $otterRelationResource::collection((new $otterRelationResource::$model)::all());
        }

        return $relationalDataArray;
    }

}