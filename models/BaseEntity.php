<?php
/**
 * Lithium: the most rad php framework
 *
 * @copyright	  Copyright 2012, Mariano Iglesias (http://marianoiglesias.com.ar)
 * @license		  http://opensource.org/licenses/bsd-license.php The BSD License
 */

namespace li3_doctrine2\models;

use lithium\util\Inflector;
use Doctrine\ORM\Event\LifecycleEventArgs;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;

/**
 * This class can be used as the base class of your doctrine models, to allow
 * for lithium validation to work on doctrine models.
 */
abstract class BaseEntity extends \lithium\data\Entity implements IModel {
	/**
	 * Criteria for data validation.
	 *
	 * Example usage:
	 * {{{
	 * public $validates = array(
	 *	   'title' => 'please enter a title',
	 *	   'email' => array(
	 *		   array('notEmpty', 'message' => 'Email is empty.'),
	 *		   array('email', 'message' => 'Email is not valid.'),
	 *	   )
	 * );
	 * }}}
	 *
	 * @var array
	 */
	protected $validates = array();

	/**
	 * Class dependencies.
	 *
	 * @var array
	 */
	protected static $_classes = array(
		'connections' => 'lithium\data\Connections',
		'validator'   => 'lithium\util\Validator'
	);

	/**
	 * Connection name used for persisting / loading this record
	 *
	 * @var string
	 */
	protected static $connectionName = 'default';

	/**
	 * Constructor, instance at which the entity fields are loaded from
	 * doctrine's class metadata
	 */
	public function __construct() {
		$this->_model = get_called_class();
	}

	/**
	 * Get connection name
	 *
	 * @return string Connection name
	 */
	public static function getConnectionName() {
		return static::$connectionName;
	}

	/**
	 * Change the connection name
	 *
	 * @param string $connectionName Connection name
	 */
	public static function setConnectionName($connectionName) {
		static::$connectionName = $connectionName;
	}

	/**
	 * Get the entity manager linked to the connection defined in the property
	 * `$connectionName`
	 *
	 * @param string $connectionName Connection name, or use the property `$connectionName` if empty
	 * @see IModel::getEntityManager()
	 * @return EntityManager entity manager
	 */
	public static function getEntityManager($connectionName = null) {
		static $entityManagers = array();
		if (!$connectionName) {
			$connectionName = static::getConnectionName();
		}
		if (!isset($entityManager[$connectionName])) {
			$connections = static::$_classes['connections'];
			$entityManagers[$connectionName] = $connections::get($connectionName)->getEntityManager();
		}
		return $entityManagers[$connectionName];
	}

	/**
	 * Get the repository for this model
	 *
	 * @param string $connectionName Connection name, or use the property `$connectionName` if empty
	 * @see IModel::getRepository()
	 * @return EntityRepository entity repository
	 */
	public static function getRepository($connectionName = null) {
		return static::getEntityManager($connectionName)->getRepository(get_called_class());
	}

	/**
	 * Doctrine callback executed after a record was loaded
	 *
	 * @param object $eventArgs Event arguments
	 */
	public function onPostLoad(LifecycleEventArgs $eventArgs) {
		$this->_exists = true;
	}

	/**
	 * Doctrine callback executed before persisting a new record
	 *
	 * @param object $eventArgs Event arguments
	 * @throws ValidateException
	 */
	public function onPrePersist(LifecycleEventArgs $eventArgs) {
		$this->_exists = false;
		if (!$this->validates()) {
			throw new ValidateException($this);
		}
	}

	/**
	 * Doctrine callback executed before persisting an existing record
	 *
	 * @param object $eventArgs Event arguments
	 * @throws ValidateException
	 */
	public function onPreUpdate(PreUpdateEventArgs $eventArgs) {
		$this->_exists = true;
		if (!$this->validates()) {
			throw new ValidateException($this);
		}
	}

	/**
	 * Perform validation
	 *
	 * @see IModel::validates()
	 * @param array $options Options
	 * @return boolean Success
	 */
	public function validates(array $options = array()) {
		$defaults = array(
			'rules' => $this->validates,
			'events' => $this->exists() ? 'update' : 'create',
			'model' => get_called_class()
		);
		$options += $defaults;
		$validator = static::$_classes['validator'];

		$rules = $options['rules'];
		unset($options['rules']);

		if (!empty($rules) && $this->_errors = $validator::check($this->_getData(true), $rules, $options)) {
			return false;
		}
		return true;
	}

   /**
	 * Allows several properties to be assigned at once, i.e.:
	 * {{{
	 * $record->set(array('title' => 'Lorem Ipsum', 'value' => 42));
	 * }}}
	 *
	 * @see IModel::validates()
	 * @param array $data An associative array of fields and values to assign to this instance.
	 * @param array $whitelist Fields to allow setting
	 * @param bool $useWhitelist Set to false to ignore whitelist
	 * @throws Exception
	 */
	public function set(array $data, array $whitelist = array(), $useWhitelist = true) {
		if (empty($data)) {
			return;
		} elseif ($useWhitelist && empty($whitelist)) {
			throw new \Exception('Must set whitelist of fields');
		}

		if ($useWhitelist) {
			$data = array_intersect_key($data, array_flip($whitelist));
		}
		foreach($data as $field => $value) {
			if (!is_string($field) || !in_array($field, $this->_getEntityFields())) {
				continue;
			}
			$method = 'set' . Inflector::camelize($field);
			if (method_exists($this, $method) && is_callable(array($this, $method))) {
			  $this->{$method}($value);
			} else if (property_exists($this, $field)) {
			  $this->{$field} = $value;
			}
		}
	}

	/**
	 * Allows several properties to be assigned at once, i.e.:
	 * {{{
	 * $record->set(array('title' => 'Lorem Ipsum', 'value' => 42));
	 * }}}
	 *
	 * @author alex.dallaway@gmail.com
	 * @see IModel::validates()
	 * @param array $data An associative array of fields and values to assign to this instance.
	 * @throws Exception
	 */
	public function setAssociations(array $data)
	{
	  $entity_associations = $this->_getEntityAssociations();
	  foreach($data as $association => $association_fields)
	  {
	    // Verify the association exists on this object and the value provided is an array:
	    if(!is_string($association) || !array_key_exists($association, $entity_associations) || empty($association_fields)) continue;

	    // We are dealing with a collection:
	    if($entity_associations[$association]['is_collection'])
	    {
	      $collection_items = array();
	      foreach($association_fields as $key => $collection_item)
	      {
	        /*
	         * Workaround for when only the id has been submitted and is empty:
	        */
	        if((count($association_fields) == 1) && isset($association_fields['id']) && empty($association_fields['id']))
	        {
	          continue;
	        }
	        
	        $target_class = isset($collection_item['__record_type']) ? $collection_item['__record_type'] : $entity_associations[$association]['target_class'];
	        if(isset($collection_item['id']))
	        {
	          $find_result = static::getEntityManager()->find($target_class, $collection_item['id']);
	          $collection_item_object = $find_result ?: new $target_class();
	        }
	        else
	        {
	          $collection_item_object = new $target_class();
	        }
	        $collection_item_object->set($collection_item, array(), false);
	        $collection_item_object->setAssociations($collection_item);
	        $collection_items[] = $collection_item_object;
	      }
	      $record_object = new \Doctrine\Common\Collections\ArrayCollection($collection_items);
	    }
	    
	    // We are dealing with a single item:
	    else
	    {
	      /*
	       * Workaround for when only the id has been submitted and is empty:
	       */
	      if((count($association_fields) == 1) && isset($association_fields['id']) && empty($association_fields['id']))
	      {
	        continue;
	      }
	      
  	    $target_class = isset($association_fields['__record_type']) ? $association_fields['__record_type'] : $entity_associations[$association]['target_class'];
  	    
  	    if(isset($association_fields['id']))
  	    {
  	      $find_result = static::getEntityManager()->find($target_class, $association_fields['id']);
  	      $record_object = $find_result ?: new $target_class();
  	    }
  	    else
  	    {
  	      $record_object = new $target_class();
  	    }
  	    
  	    $record_object->set($association_fields, array(), false);
  	    $record_object->setAssociations($association_fields);
	    }
	    
	    $set_method = 'set'.Inflector::camelize($association);
	    if(method_exists($this, $set_method) && is_callable(array($this, $set_method)))
	    {
	      $this->{$set_method}($record_object);
	    }
	    else if(property_exists($this, $association))
	    {
	      $this->{$association} = $record_object;
	    }
	    
	    // Don't try to persist the collections:
	    if(get_class($record_object) != 'Doctrine\Common\Collections\ArrayCollection')
	    {
  	    $get_method = 'get'.Inflector::camelize($association);
  	    if(method_exists($this, $get_method) && is_callable(array($this, $get_method)))
  	    {
  	      static::getEntityManager()->persist($this->{$get_method}());
  	    }
  	    else if(property_exists($this, $association))
  	    {
  	      static::getEntityManager()->persist($this->{$association});
  	    }
	    }
	  }
	}

	/**
	 * Access the data fields of the record. Can also access a $named field.
	 * Only returns data for fields that have a getter method defined.
	 *
	 * @see IModel::validates()
	 * @param string $name Optionally included field name.
	 * @param bool $allProperties If true, get also properties without getter methods
	 * @return mixed Entire data array if $name is empty, otherwise the value from the named field.
	 */
	public function data($name = null, $allProperties = false)
	{
		$data = $this->_getData($allProperties);
		if(isset($name))
		{
		  if(strpos($name, '.'))
        return $this->_getNested($name);
      else
			  return array_key_exists($name, $data) ? $data[$name] : null;
		}
		return $data;
	}
  
  /**
   * Gets a nested field value of this object.
   * 
   * Used by data() to retrieve nested form field values for the Lithium Form helper.
   * 
   * @author alex.dallaway@gmail.com
   * @return mixed The value of the nested field or null if it doesn't exist.
   * @todo Rewrite this function to traverse as far as needed and handle collections.
   */
  protected function _getNested($name)
  {
    $current = $this;
    $null = null;
    $path = explode('.', $name);
    $length = count($path) - 1;

    $method = 'get' . Inflector::camelize($path[0]);
      
    if(method_exists($this, $method) && is_callable(array($this, $method)))
      $nestedobj = $this->{$method}();
    else
      return null;
    
    $methodvar = 'get' . Inflector::camelize($path[1]);
    
    if(method_exists($nestedobj, $methodvar) && is_callable(array($nestedobj, $methodvar)))
    {
      return $nestedobj->{$methodvar}();
    }
    else
      return null;
    
    return null;
  }
  
  /**
   * Gets a string representation of this object. This is used for record list display.
   * 
   * This should be overridden in any objects that extend this class to return more than the ID.
   * (For example 'Brand' would return $this->getId().' '.$this->getName())
   * 
   * @author alex.dallaway@gmail.com
   * @return string A string representation of this object.
   */
  public function toString()
  {
    return $this->getId();
  }

	/**
	 * Get the entity fields
	 *
	 * @return array
	 */
	protected function _getEntityFields() {
		static $entityFields;
		if (!isset($entityFields)) {
			$entityFields = array_values(static::getEntityManager()->getClassMetadata(get_called_class())->fieldNames);
			if (empty($entityFields)) {
				throw new \Exception($class . ' does not seem to have fields defined');
			}
		}
		return $entityFields;
	}
	
	/**
	 * Get the entity associations
	 *
	 * @author alex.dallaway@gmail.com
	 * @return array
	 */
	protected function _getEntityAssociations()
	{
	  static $entity_associations;
	  if(!isset($entity_associations))
	  {
	    $meta_data = static::getEntityManager()->getClassMetadata(get_called_class());
	    $association_mappings = $meta_data->getAssociationMappings();
	    $entity_associations = array();
	    foreach($association_mappings as $field => $mapping_data)
	    {
	      $entity_associations[$field] = array();
	      $entity_associations[$field]['target_class'] = $meta_data->getAssociationTargetClass($field);
	      $entity_associations[$field]['is_collection'] = $meta_data->isCollectionValuedAssociation($field);
	    }
	  }
	  return $entity_associations;
	}

	/**
	 * Get record data as an array
	 *
	 * @param bool $allProperties If true, get also properties without getter methods
	 * @return array Data
	 */
	protected function _getData($allProperties = false) {
		$data = array();
		foreach($this->_getEntityFields() as $field) {
			$method = 'get' . Inflector::camelize($field);
			if (method_exists($this, $method) && is_callable(array($this, $method))) {
				$data[$field] = $this->{$method}();
			} elseif ($allProperties && property_exists($this, $field)) {
				$data[$field] = $this->{$field};
			}
		}
		if (isset($name)) {
			return array_key_exists($name, $data) ? $data[$name] : null;
		}
		return $data;
	}
}
?>
