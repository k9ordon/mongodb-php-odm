<?php
/**
 * This class objectifies a Mongo document. It must be extended with your own class and used alongside a Mongo_Collection
 * where the database configuration name and collection name are specified. Classes which extend Mongo_Document are intended
 * to contain only methods which pertain to documents (e.g. validation) whereas classes which extend Mongo_Collection are
 * intended to contain methods which pertain to the collection as a whole (e.g. advanced queries). The most basic use of this
 * class is an empty extension, but all extensions of Mongo_Document must be accompanied by an extension of Mongo_Collection which
 * is named after the document with _Collection appended:
 * 
 *   class Document extends Mongo_Document {}
 *   class Document_Collection extends Mongo_Collection {
 *     public $name = 'test';
 *   }
 *   $document = new Document();
 *   $document->name = 'Mongo';
 *   $document->type = 'db';
 *   $document->save();
 *   // db.test.save({"name":"Mongo","type":"db"});
 *
 * The _id is aliased to id by default. Other aliases can also be defined using the _aliases protected property. Aliases can be used
 * anywhere that a field name can be used including dot-notation for nesting.
 *
 *   $id = $document->id;  // MongoId
 *
 *   $document->load('{name:"Mongo"}');
 *   // db.test.findOne({"name":"Mongo"});
 * 
 * Methods which are intended to be overridden are {before,after}_{save,load,delete} so that special actions may be
 * taken when these events occur:
 *
 *   public function before_save()
 *   {
 *     $this->inc('visits');
 *     $this->last_visit = time();
 *   }
 *
 * When a document is saved, update will be used if the document already exists, otherwise insert will be used, determined
 * by the presence of an _id. A document can be modified without being loaded from the database if an _id is passed to the constructor:
 * 
 *   $doc = new Document($id);
 * 
 * Atomic operations and updates are not executed until save() is called and operations are chainable. Example:
 * 
 *   $doc->inc('uses.boing');
 *       ->push('used',array('type' => 'sound', 'desc' => 'boing'));
 *   $doc->inc('uses.bonk');
 *       ->push('used',array('type' => 'sound', 'desc' => 'bonk'));
 *       ->save();
 *   // db.test.update(
 *   //   {"_id":"one"},
 *   //   {"$inc":{"uses.boing":1,"uses.bonk":1},"$pushAll":{"used":[{"type":"sound","desc":"boing"},{"type":"sound","desc":"bonk"}]}}
 *   // );
 *
 * Documents are loaded lazily so if a property is accessed and the document is not yet loaded, it will be loaded on the first property access:
 *
 *   echo "$doc->name rocks!";
 *   // Mongo rocks!
 *
 * Documents are reloaded when accessing a property that was modified with an operator and then saved:
 *
 *   in_array($doc->roles,'admin');
 *   // TRUE
 *   $doc->pull('roles','admin');
 *   in_array($doc->roles,'admin');
 *   // TRUE
 *   $doc->save();
 *   in_array($doc->roles,'admin');
 *   // FALSE
 *
 * Documents can have references to other documents which will be loaded lazily and saved automatically.
 *
 *   class Model_Post extends Mongo_Document {
 *     protected $_references = array('user' => array('model' => 'user'));
 *   }
 *
 *   class Model_User extends Mongo_Document {
 *   }
 *
 *   $user = Mongo_Document::factory('user')->set('id','colin')->set('email','colin@mollenhour.com');
 *   $post = Mongo_Document::factory('post');
 *   $post->user = $user;
 *   $post->title = 'MongoDb';
 *   $post->save()
 *   // save({"_id":"colin","email":"colin@mollenhour.com"})
 *   // save({"_id":Object,"_user":"colin","title":"MongoDb"})
 *
 *   $post = new Model_Post($id);
 *   $post->_user;
 *   // "colin" - the post was loaded lazily.
 *   $post->user->id;
 *   // "colin" - the user object was created lazily but not loaded.
 *   $post->user->email;
 *   // "colin@mollenhour.com" - now the user document was loaded as well.
 *
 * @package Mongo_Database
 */

abstract class Mongo_Document {

  /**
   * Instantiate an object conforming to Mongo_Document conventions.
   * The document is not loaded until load() is called.
   *
   * @param   string  model name
   * @param   string  optional _id of document to operate on (if you expect it exists)
   * @return  Mongo_Document
   */
  public static function factory($name, $id = NULL)
  {
    $class = 'Model_'.$name;
    return new $class($id);
  }

  /** @var  string  database collection instance cached */
  protected $_collection;

  /** @var  array  definition of references existing in this document {user: {model: 'user', field: 'user_id'} */
  protected $_references = array();

  /** @var  array  key name aliases {db_key: 'alias'} */
  protected $_aliases = array();

  /** @var  array  object data */
  protected $_object = array();

  /** @var  array  changed fields */
  protected $_changed = array();

  /** @var  array  set of operations to perform (not including $set) */
  protected $_operations = array();

  /** @var  array  flags for tracking what data is dirty */
  protected $_dirty = array();

  /** @var  boolean  Is the document loaded */
  protected $_loaded = FALSE;

  /**
   * Instantiate a new Document object. If an id is passed then it will be assumed that the
   * document exists in the database and updates will be performaed without loading the document first.
   *
   * @param   string  The _id of the document to operate on
   * @return  void
   */
  public function __construct($id = NULL)
  {
    if($id)
    {
      $this->_object['_id'] = $id;
    }
  }

  /**
   * This function translates an alias to a database field name.
   * Aliases are defined in $this->_aliases, and id is always aliased to _id.
   * You can override this to disable alises or define your own aliasing technique.
   * 
   * @param   string  $name  The aliased field name
   * @return  string  The field name used within the database
   */
  public function get_field_name($name, $dot_allowed = TRUE)
  {
    if($name == 'id') return '_id';

    if( ! $dot_allowed || ! strpos($name,'.'))
    {
      return (isset($this->_aliases[$name])
        ? $this->_aliases[$name]
        : $name
      );
    }

    return implode('.', array_map( array($this,'get_field_name'), explode('.',$name) ) );
  }

  /**
   * Clones each of the fields and empty the model.
   *
   * @return  void
   */
  public function __clone()
  {
    $this->clear();
  }

  /**
   * Returns the attributes that should be serialized.
   *
   * @return  void
   */
  public function __sleep()
  {
    return array('_references', '_aliases', '_object', '_changed', '_operations', '_loaded', '_dirty');
  }

  /**
   * Checks if a field is set
   *
   * @return  boolean  field is set
   */
  public function __isset($name)
  {
    $name = $this->get_field_name($name, FALSE);
    return isset($this->_object[$name]);
  }

  /**
   * Unset a field
   *
   * @return  void
   */
  public function __unset($name)
  {
    $this->_unset($name);
  }

  /**
   * Clear the document data
   *
   * @return  Mongo_Document
   */
  public function clear()
  {
    $this->_object = $this->_changed = $this->_operations = $this->_dirty = array();
    $this->_loaded = FALSE;
    return $this;
  }

  /**
   * Return TRUE if field has been changed
   *
   * @param   string   field name (no parameter returns TRUE if there are *any* changes)
   * @return  boolean  field has been changed
   */
  public function is_changed($name = NULL)
  {
    if($name === NULL)
    {
      return ($this->_changed || $this->_operations);
    }
    else
    {
      $name = $this->get_field_name($name);
      return isset($this->_changed[$name]) || isset($this->_dirty[$name]);
    }
  }

  /**
   * Return the Mongo_Database reference (proxy to the collection's db() method)
   *
   * @return  Mongo_Database
   */
  public function db()
  {
    return $this->collection()->db();
  }

  /**
   * Get a corresponding collection instance
   *
   * @return Mongo_Collection
   */
  public function collection()
  {
    if ( ! $this->_collection)
    {
      $class_name = get_class($this).'_Collection';
      $this->_collection = new $class_name;
    }
    return $this->_collection;
  }

  /**
   * Get the value of a field.
   *
   * @param   string  field name
   * @return  mixed
   */
  public function __get($name)
  {
    $name = $this->get_field_name($name, FALSE);

    // Auto-loading for special references
    if(isset($this->_references[$name]))
    {
      if( ! isset($this->_references[$name]['object']))
      {
        $id_field = Arr::get($this->_references[$name], 'field', "_$name");
        $this->_references[$name]['object'] = Mongo_Document::factory($this->_references[$name]['model'], $this->$id_field);
      }
      return $this->_references[$name]['object'];
    }

    // Reload when retrieving dirty data
    if($this->_loaded && empty($this->_operations) && ! empty($this->_dirty[$name]))
    {
      $this->load();
    }

    // Lazy loading!
    else if( ! $this->_loaded && isset($this->_object['_id']) && ! isset($this->_changed['_id']) && $name != '_id')
    {
      $this->load();
    }

    return isset($this->_object[$name]) ? $this->_object[$name] : NULL;
  }

  /**
   * Magic method for setting the value of a field. In order to set the value of a nested field,
   * you must use the "set" method, not the magic method. Examples:
   *
   * // Works
   * $doc->set('address.city', 'Knoxville');
   *
   * // Does not work
   * $doc->address['city'] = 'Knoxville';
   *
   * @param   string  field name
   * @param   mixed   new field value
   * @return  mixed
   */
  public function __set($name, $value)
  {
    $name = $this->get_field_name($name, FALSE);

    // Automatically save references to other Mongo_Document objects
    if(isset($this->_references[$name]))
    {
      if( ! $value instanceof Mongo_Document)
      {
        throw new Exception('Cannot set reference to object that is not a Mongo_Document');
      }
      $this->_references[$name]['object'] = $value;
      if(isset($value->id))
      {
        $id_field = Arr::get($this->_references[$name], 'field', "_$name");
        $this->$id_field = $value->id;
      }
      return;
    }

    // Do not save sets that result in no change
    if ( isset($this->_object[$name]) && $this->_object[$name] === $value)
    {
      return;
    }

    $this->_object[$name] = $value;
    $this->_changed[$name] = TRUE;
  }

  protected function _set_dirty($name)
  {
    if($pos = strpos($name,'.'))
    {
      $name = substr($name,0,$pos);
    }
    $this->_dirty[$name] = TRUE;
    return $this;
  }

  /**
   * Set the value for a key. This function must be used when updating nested documents.
   *
   * @param   string  $name The key of the data to update (use dot notation for embedded objects)
   * @param   mixed   $value The data to be saved
   * @return  Mongo_Document
   */
  public function set($name, $value)
  {
    $name = $this->get_field_name($name);
    $this->_operations['$set'][$name] = $value;
    return $this->_set_dirty($name);
  }

  /**
   * Unset a key
   *
   * @param   string  $name The key of the data to update (use dot notation for embedded objects)
   * @return Mongo_Document
   */
  public function _unset($name)
  {
    $name = $this->get_field_name($name);
    $this->_operations['$unset'][$name] = 1;
    return $this->_set_dirty($name);
  }

  /**
   * Increment a value atomically
   *
   * @param   string  $name The key of the data to update (use dot notation for embedded objects)
   * @param   mixed   $value The amount to increment by (default is 1)
   * @return  Mongo_Document
   */
  public function inc($name, $value = 1)
  {
    $name = $this->get_field_name($name);
    if(isset($this->_operations['$inc'][$name]))
    {
      $this->_operations['$inc'][$name] += $value;
    }
    else
    {
      $this->_operations['$inc'][$name] = $value;
    }
    return $this->_set_dirty($name);
  }

  /**
   * Push a vlaue to an array atomically. Can be called multiple times.
   *
   * @param   string  $name The key of the data to update (use dot notation for embedded objects)
   * @param   mixed   $value The value to push
   * @return  Mongo_Document
   */
  public function push($name, $value)
  {
    $name = $this->get_field_name($name);
    if(isset($this->_operations['$pushAll'][$name]))
    {
      $this->_operations['$pushAll'][$name][] = $value;
    }
    else if(isset($this->_operations['$push'][$name]))
    {
      $this->_operations['$pushAll'][$name] = array($this->_operations['$push'][$name],$value);
      unset($this->_operations['$push'][$name]);
    }
    else
    {
      $this->_operations['$push'][$name] = $value;
    }
    return $this->_set_dirty($name);
  }

  /**
   * Push an array of values to an array in the document
   *
   * @param   string  $name The key of the data to update (use dot notation for embedded objects)
   * @param   array   $value An array of values to push
   * @return  Mongo_Document
   */
  public function pushAll($name, $value)
  {
    $name = $this->get_field_name($name);
    if(isset($this->_operations['$pushAll'][$name]))
    {
      $this->_operations['$pushAll'][$name] += $value;
    }
    else
    {
      $this->_operations['$pushAll'][$name] = $value;
    }
    return $this->_set_dirty($name);
  }

  /**
   * Pop a value from the end of an array
   *
   * @param   string  $name The key of the data to update (use dot notation for embedded objects)
   * @return  Mongo_Document
   */
  public function pop($name)
  {
    $name = $this->get_field_name($name);
    $this->_operations['$pop'][$name] = 1;
    return $this->_set_dirty($name);
  }

  /**
   * Pop a value from the beginning of an array
   *
   * @param   string  $name The key of the data to update (use dot notation for embedded objects)
   * @return  Mongo_Document
   */
  public function shift($name)
  {
    $name = $this->get_field_name($name);
    $this->_operations['$pop'][$name] = -1;
    return $this->_set_dirty($name);
  }

  /**
   * Pull (delete) a value from an array
   *
   * @param   string  $name The key of the data to update (use dot notation for embedded objects)
   * @param   mixed   $value
   * @return  Mongo_Document
   */
  public function pull($name, $value)
  {
    $name = $this->get_field_name($name);
    if(isset($this->_operations['$pullAll'][$name]))
    {
      $this->_operations['$pullAll'][$name][] = $value;
    }
    else if(isset($this->_operations['$pull'][$name]))
    {
      $this->_operations['$pullAll'][$name] = array($this->_operations['$pull'][$name],$value);
      unset($this->_operations['$pull'][$name]);
    }
    else
    {
      $this->_operations['$pull'][$name] = $value;
    }
    return $this->_set_dirty($name);
  }

  /**
   * Pull (delete) all of the given values from an array
   *
   * @param   string  $name The key of the data to update (use dot notation for embedded objects)
   * @param   array   $value An array of value to pull from the array
   * @return  Mongo_Document
   */
  public function pullAll($name, $value)
  {
    $name = $this->get_field_name($name);
    if(isset($this->_operations['$pullAll'][$name]))
    {
      $this->_operations['$pullAll'][$name] += $value;
    }
    else
    {
      $this->_operations['$pullAll'][$name] = $value;
    }
    return $this->_set_dirty($name);
  }

  /**
   * Bit operators
   *
   * @param   string  $name The key of the data to update (use dot notation for embedded objects)
   * @return  Mongo_Document
   */
  public function bit($name,$value)
  {
    $name = $this->get_field_name($name);
    $this->_operations['$bit'][$name] = $value;
    return $this->_set_dirty($name);
  }

  /**
   * Load all of the values in an associative array. Ignores all fields
   * not in the model.
   *
   * @param   array    field => value pairs
   * @param   boolean  values are clean (from database)?
   * @return  Mongo_Document
   */
  public function load_values($values, $clean = FALSE)
  {
    if($clean === TRUE)
    {
      $this->before_load();

      if(empty($this->_object))
      {
        $this->_object = $values;
      }
      else
      {
        foreach ($values as $field => $value)
        {
          $this->_object[$field] = $value;
        }
      }

      $this->after_load();
    }
    else
    {
      foreach ($values as $field => $value)
      {
        $this->__set($field, $value);
      }
    }
    
    return $this;
  }

  /**
   * Get the model data as an associative array.
   *
   * @param   boolean  retrieve values directly from _object
   * @return  array  field => value
   */
  public function as_array( $clean = FALSE )
  {
    if($clean === TRUE)
    {
      $array = $this->_object;
    }
    else
    {
      $array = array();
      foreach($this->_object as $name => $value)
      {
        $array[$name] = $this->__get($name);
      }
      foreach($this->_aliases as $name => $alias)
      {
        $array[$alias] = $array[$name];
        unset($array[$name]);
      }
    }

    return $array;
  }

  /**
   * Return true if the document is loaded.
   *
   * @return  boolean
   */
  public function loaded()
  {
    return $this->_loaded;
  }

  /**
   * Load the document from the database. The first parameter may be one of:
   *
   *  a falsey value - the object data will be used to construct the query
   *  a JSON string - will be parsed and used for the query
   *  an non-array value - the query will be assumed to be for an _id of this value
   *  an array - the array will be used for the query
   *
   * @param   array  specify additional criteria
   * @param   array  specify the fields to return
   * @return  Mongo_Document
   */
  public function load($criteria = array(), array $fields = array())
  {
    // Use of json for querying is allowed
    if(is_string($criteria) && $criteria[0] == "{")
    {
      $criteria = JSON::arr($criteria);
    }

    else if($criteria && ! is_array($criteria))
    {
      $criteria = array('_id' => $criteria);
    }

    else if(isset($this->_object['_id']))
    {
      $criteria = array('_id' => $this->_object['_id']);
    }

    else if(isset($criteria['id']))
    {
      $criteria = array('_id' => $criteria['id']);
    }

    else if( ! $criteria)
    {
      $criteria = $this->_object;
    }

    // If a non _id query is used, translate aliases appropriately
    if( ! isset($criteria['_id']))
    {
      $new = array();
      foreach($criteria as $key => $value)
      {
        $new[$this->get_field_name($key)] = $value;
      }
      $criteria = $new;
    }

    $this->clear();

    if( ! $criteria)
    {
       throw new MongoException('Cannot find '.get_class($this).' without _id or other search criteria.');
    }

    // Convert _id to a MongoId instance if applicable
    if(isset($criteria['_id']) &&  ! $criteria['_id'] instanceof MongoId)
    {
      $id = new MongoId($criteria['_id']);
      if( (string) $id == $criteria['_id'])
      {
        $criteria['_id'] = $id;
      }
    }

    $values = $this->collection()->collection()->findOne($criteria,$fields);

    if($values)
    {
      $this->_loaded = TRUE;
      $this->load_values($values, TRUE);
    }

    return $this;
  }

  /**
   * Save the document to the database. For newly created documents the _id will be retrieved.
   *
   * @param   boolean  $safe  If FALSE the insert status will not be checked
   * @return  Mongo_Document
   */
  public function save($safe = TRUE)
  {
    // Save changes to or create referenced objects
    $this->save_references($safe);
    
    // Insert new record if no _id or _id was set by user
    if( ! isset($this->_object['_id']) || isset($this->_changed['_id']))
    {
      $this->before_save('insert');

      $values = array();
      foreach($this->_changed as $name => $_true)
      {
        $values[$name] = $this->_object[$name];
      }

      if(empty($values))
      {
        throw new MongoException('Cannot insert empty array.');
      }

      $err = $this->collection()->insert($values, $safe);

      if( $safe && $err['err'] )
      {
        throw new MongoException('Unable to insert '.get_class($this).': '.$err['err']);
      }

      if ( ! isset($this->_object['id']))
      {
        // Store (assigned) MongoID in object
        $this->_object['_id'] = $values['_id'];
        $this->_loaded = TRUE;
      }

      // Save any additional operations
      if($this->_operations)
      {
        if( ! $this->collection()->update(array('_id' => $this->_object['_id']), $this->_operations))
        {
          $err = $this->db()->last_error();
          throw new MongoException('Update of '.get_class($this).' failed: '.$err['err']);
        }
      }
    }

    // Update assumed existing document
    else
    {
      $this->before_save('update');

      if($this->_changed)
      {
        foreach($this->_changed as $name => $_true)
        {
          $this->_operations['$set'][$name] = $this->_object[$name];
        }
      }

      if($this->_operations)
      {
        if( ! $this->collection()->update(array('_id' => $this->_object['_id']), $this->_operations))
        {
          $err = $this->db()->last_error();
          throw new MongoException('Update of '.get_class($this).' failed: '.$err['err']);
        }
      }
    }

    $this->_changed = $this->_operations = array();

    $this->after_save();
    
    return $this;
  }

  protected function save_references($safe = TRUE)
  {
    foreach($this->_references as $name => $ref)
    {
      if(isset($ref['object']))
      {
        if( ! isset($ref['object']->id))
        {
          $ref['object']->save($safe);
          $name = Arr::get($this->_references[$name], 'field', "_$name");
          $this->$name = $ref['object']->id;
        }
        else if($ref['object']->is_changed())
        {
          $ref['object']->save($safe);
        }
      }
    }
  }

  /**
   * Override this method to take certain actions before the data is saved
   *
   * @param   string  $action  The type of save action ('insert','update','upsert')
   */
  protected function before_save($state){}

  /**
   * Override this method to take actions after data is saved
   */
  protected function after_save(){}

  /**
   * Override this method to take actions before the values are loaded
   */
  protected function before_load(){}

  /**
   * Override this method to take actions after the values are loaded
   */
  protected function after_load(){}

  /**
   * Override this method to take actions before the document is deleted
   */
  protected function before_delete(){}

  /**
   * Override this method to take actions after the document is deleted
   */
  protected function after_delete(){}

  /**
   * Upsert the document, does not retrieve the _id of the upserted document.
   *
   * @param   array $operations
   * @return  Mongo_Document
   */
  public function upsert($operations = array())
  {
    if( ! $this->_object)
    {
      throw new MongoException('Cannot upsert '.get_class($this).': no criteria');
    }

    $this->before_save('upsert');

    $operations = Arr::merge($this->_operations, $operations);

    if( ! $this->collection()->update($this->_object, $operations, array('upsert' => TRUE)))
    {
      $err = $this->db()->last_error();
      throw new MongoException('Upsert of '.get_class($this).' failed: '.$err['err']);
    }

    $this->_changed = $this->_operations = array();

    $this->after_save();

    return $this;
  }

  /**
   * Delete the current document using the current data. The document does not have to be loaded.
   * Use $doc->collection()->remove($criteria) to delete multiple documents.
   *
   * @return  Mongo_Document
   */
  public function delete()
  {
    if( ! isset($this->_object['_id']))
    {
      throw new MongoException('Cannot delete '.get_class($this).' without the _id.');
    }
    $this->before_delete();
    $criteria = array('_id' => $this->_object['_id']);

    if( ! $this->collection()->remove($criteria, TRUE))
    {
      throw new MongoException('Failed to delete '.get_class($this));
    }

    $this->clear();
    $this->after_delete();

    return $this;
  }

}
