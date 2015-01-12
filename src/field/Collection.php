<?php

namespace sndsgd\field;

use \Exception;
use \InvalidArgumentException;
use \sndsgd\Field;
use \sndsgd\Arr;


/**
 * A base class for a collection of fields
 */
class Collection
{
   use \sndsgd\event\Target, \sndsgd\data\Manager;

   /**
    * The data event key for the collection object whenever an event is
    * fired at a field
    * 
    * @var string
    */
   const EVENT_DATA_KEY = 'collection';

   /**
    * All fields currently in the collection
    * 
    * @var array.<string,sndsgd\Field>
    */
   protected $fields = [];

   /**
    * A map of field aliases pointing to field names
    *
    * @var array.<string,string>
    */
   protected $fieldAliases = [];

   /**
    * Any errors encountered during validation
    * 
    * @var array.<sndsgd\field\ValidationError>|null
    */
   protected $validationErrors = [];

   /**
    * Create a new field collection
    * 
    * @param array.<sndsgd\Field>|null $fields Fields to add to the collection
    */
   public function __construct(array $fields = null)
   {
      if ($fields !== null) {
         $this->addFields($fields);
      }
   }

   /**
    * Define a field
    * 
    * @param sndsgd\Field $field The field to define
    * @return sndsgd\field\Collection
    */
   protected function addField(Field $field)
   {
      $name = $field->getName();
      if (array_key_exists($name, $this->fieldAliases)) {
         throw new Exception(
            "failed to define field; the name '$name' is already defined"
         );
      }
      $this->fields[$name] = $field;
      $this->fieldAliases[$name] = $name;

      foreach ($field->getAliases() as $alias) {
         if (array_key_exists($alias, $this->fieldAliases)) {
            throw new Exception(
               "failed to define field; the alias '$alias' is already in use"
            );
         }
         $this->fieldAliases[$alias] = $name;
      }

      return $this;
   }

   /**
    * Define fields
    *
    * @param sndsgd\field\Field $field,... 
    * @return sndsgd\field\Collection This instance
    */
   public function addFields()
   {
      foreach (func_get_args() as $field) {
         # allow an array of fields to be passed
         # its convenient to pass the result of function call this way
         if (is_array($field)) {
            call_user_func_array([$this, 'addFields'], $field);
         }
         else {
            $this->addField($field);
         }
      }
      return $this;
   }

   /**
    * Get a field instance
    * 
    * @param string $name An alias or name of the field to get
    * @return sndsgd\Field|null
    * @return sndsgd\Field The field was found
    * @return null The field was NOT found
    */
   public function getField($name)
   {
      if (!array_key_exists($name, $this->fieldAliases)) {
         return null;
      }
      $index = $this->fieldAliases[$name];
      return $this->fields[$index];
   }

   /**
    * Get an associative array of the fields in this collection
    * 
    * @return array.<string,sndsgd\Field>
    */
   public function getFields()
   {
      return $this->fields;
   }

   /**
    * Add values to the fields in the collection
    *
    * @param array.<string,mixed> $fieldValues
    */
   public function addValues(array $fieldValues)
   {
      foreach ($fieldValues as $fieldName => $values) {
         $field = $this->getField($fieldName);
         foreach (Arr::cast($values) as $index => $value) {
            if ($field === null) {
               $this->addValidationError(
                  new ValidationError(
                     'unknown parameter',
                     $value,
                     $fieldName,
                     $index
                  )
               );
            }
            else {
               $field->addValue($value);
            }
         }
      }
   }

   /**
    * Validate the all the fields
    * 
    * @return boolean True if no errors were encountered, otherwise false
    */
   public function validate()
   {
      # note: addValues adds validation errors for unknown values
      $errs = count($this->validationErrors);

      $dataKey = constant(get_called_class().'::EVENT_DATA_KEY');

      if ($this->fire('beforeValidate', [$dataKey => $this]) === false) {
         return false;
      }
      foreach ($this->fields as $field) {
         $errs += $field->validate($this);
      }
      return (
         $errs > 0 ||
         $this->fire('afterValidate', [$dataKey => $this]) === false ||
         count($this->validationErrors) > 0
      ) ? false : true;
   }

   /**
    * Add a validation error
    * 
    * @param sndsgd\field\ValidationError $error
    * @param boolean $unshift Add the error to the beginning
    * @return integer The total number of validation errors
    */
   public function addValidationError(ValidationError $error, $unshift = false)
   {
      if ($unshift === true) {
         array_unshift($this->validationErrors, $error);
      }
      else {
         $this->validationErrors[] = $error;
      }
      return count($this->validationErrors);
   }

   /**
    * Determine if any validation errors exist
    * 
    * @return boolean
    */
   public function hasValidationErrors()
   {
      return (count($this->validationErrors) !== 0);
   }

   /**
    * Get validation errors for one or all fields
    *
    * @param string|null $name The name of a field to get validation errors for
    * @return array.<sndsgd\field\ValidationError>
    */
   public function getValidationErrors($name = null)
   {
      if ($name === null) {
         return $this->validationErrors;
      }

      $ret = [];
      foreach ($this->validationErrors as $validationError) {
         if ($validationError->getName() === $name) {
            $ret[] = $validationError;
         }
      }
      return $ret;
   }

   /**
    * Convenience method to get a particular field value
    * 
    * @return mixed
    * @throws InvalidArgumentException If provided name does not exist
    */
   public function exportFieldValue($name)
   {
      if (!is_string($name)) {
         throw new InvalidArgumentException(
            "invalid value provided for 'name'; ".
            "expecting a field name as string"
         );
      }
      else if (($field = $this->getField($name)) == null) {
         throw new UnknownFieldException(
            "invalid value provided for 'name'; ".
            "the field '{$name}' does not exist in the collection"
         );
      }
      return $field->exportValue();
   }

   /**
    * Get all field values using their respective export handlers
    *
    * @return  array.<string,mixed>
    */
   public function exportValues()
   {
      $ret = [];
      foreach ($this->fields as $field) {
         if ($field->getExportHandler() !== Field::EXPORT_SKIP) {
            $ret[$field->getName()] = $field->exportValue();
         }
      }
      return $ret;
   }
}

