<?php
/**
 * @package    DPFields
 * @author     Digital Peak http://www.digital-peak.com
 * @copyright  Copyright (C) 2015 - 2015 Digital Peak. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl.html GNU/GPL
 */
defined('_JEXEC') or die();

class DPFieldsHelper
{

	private static $fieldsCache = null;
	private static $fieldCache = null;

	/**
	 * Extracts the component and section from the context string which has to
	 * be in format component.context.
	 *
	 * @param string $contextString
	 * @return array|null
	 */
	public static function extract ($contextString)
	{
		$parts = explode('.', $contextString);
		if (count($parts) < 2)
		{
			return null;
		}
		return $parts;
	}

	/**
	 * Creates an object of the given type.
	 *
	 * @param string $type
	 * @param string $context
	 *
	 * @return DPFieldsTypeBase
	 */
	public static function loadTypeObject ($type, $context)
	{
		// Loading the class
		$class = 'DPFieldsType' . JString::ucfirst($type);
		if (class_exists($class))
		{
			return new $class();
		}

		// Searching the file
		$paths = array(
				JPATH_ADMINISTRATOR . '/components/com_dpfields/models/types'
		);

		if ($context)
		{
			// Extracting the component and section
			$parts = self::extract($context);
			if ($parts)
			{
				$component = $parts[0];

				$paths[] = JPATH_ADMINISTRATOR . '/components/' . $component . '/models/types';
			}
		}

		// Search for the file and load it
		$file = JPath::find($paths, $type . '.php');
		if ($file !== false)
		{
			require_once $file;
		}

		return class_exists($class) ? new $class() : false;
	}

	/**
	 * Returns the fields for the given context.
	 * If the item is an object the returned fields do have an additional field
	 * "value" which represents the value for the given item. If the item has a
	 * catid field, then additionally fields which belong to that category will
	 * be returned.
	 * Should the value being prepared to be shown in a HTML context
	 * prepareValue must be set to true. Then no further escaping needs to be
	 * don.
	 *
	 * @param string $context
	 * @param stdClass $item
	 * @param boolean $prepareValue
	 * @return array
	 */
	public static function getFields ($context, $item = null, $prepareValue = false)
	{
		if (self::$fieldsCache === null)
		{
			// Load the model
			JLoader::import('joomla.application.component.model');
			JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_dpfields/models', 'DPFieldsModel');
			self::$fieldsCache = JModelLegacy::getInstance('Fields', 'DPFieldsModel', array(
					'ignore_request' => true
			));
			self::$fieldsCache->setState('filter.published', 1);
			self::$fieldsCache->setState('filter.language', JFactory::getLanguage()->getTag());
			self::$fieldsCache->setState('list.limit', 0);
		}
		self::$fieldsCache->setState('filter.context', $context);

		if (is_array($item))
		{
			$item = (object) $item;
		}

		// If item has catid parameter display only fields which belong to the
		// category
		if ($item && isset($item->catid))
		{
			self::$fieldsCache->setState('filter.catid', is_array($item->catid) ? $item->catid : explode(',', $item->catid));
		}

		$fields = self::$fieldsCache->getItems();
		if ($item && isset($item->id))
		{
			if (self::$fieldCache === null)
			{
			self::$fieldCache = JModelLegacy::getInstance('Field', 'DPFieldsModel', array(
					'ignore_request' => true
			));}
			foreach ($fields as $field)
			{
				$field->value = self::$fieldCache->getFieldValue($field->id, $field->context, $item->id);
				if (! $field->value)
				{
					$field->value = $field->default_value;
				}

				if ($prepareValue)
				{
					$type = self::loadTypeObject($field->type, $field->context);
					if ($type)
					{
						$field->value = $type->prepareValueForDisplay($field->value, $field);
					}
				}
			}
		}
		return $fields;
	}

	public static function addSubmenu ($context)
	{
		// Avoid nonsense situation.
		if ($context == 'com_dpfields')
		{
			return;
		}

		$parts = self::extract($context);
		if (! $parts)
		{
			return;
		}
		$component = $parts[0];
		$section = $parts[1];

		// Try to find the component helper.
		$eName = str_replace('com_', '', $component);
		$file = JPath::clean(JPATH_ADMINISTRATOR . '/components/' . $component . '/helpers/' . $eName . '.php');

		if (file_exists($file))
		{
			require_once $file;

			$prefix = ucfirst(str_replace('com_', '', $component));
			$cName = $prefix . 'Helper';

			if (class_exists($cName))
			{
				if (is_callable(array(
						$cName,
						'addSubmenu'
				)))
				{
					$lang = JFactory::getLanguage();

					// Loading language file from the administrator/language
					// directory then
					// loading language file from the
					// administrator/components/*context*/language directory
					$lang->load($component, JPATH_BASE, null, false, true) ||
							 $lang->load($component, JPath::clean(JPATH_ADMINISTRATOR . '/components/' . $component), null, false, true);

					call_user_func(array(
							$cName,
							'addSubmenu'
					), 'fields' . (isset($section) ? '.' . $section : ''));
				}
			}
		}
	}

	public static function where ()
	{
		$e = new Exception();
		$trace = '<pre>' . $e->getTraceAsString() . '</pre>';

		echo $trace;
	}
}