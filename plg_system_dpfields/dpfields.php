<?php
/**
 * @package    DPFields
 * @author     Digital Peak http://www.digital-peak.com
 * @copyright  Copyright (C) 2015 - 2015 Digital Peak. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl.html GNU/GPL
 */
defined('_JEXEC') or die();

use Joomla\Registry\Registry;

JLoader::register('DPFieldsHelper', JPATH_ADMINISTRATOR . '/components/com_dpfields/helpers/dpfields.php');
JLoader::import('joomla.filesystem.folder');
JLoader::import('joomla.filesystem.file');

class PlgSystemDPFields extends JPlugin
{

	const DEFAULT_HIDE_ALIAS = 'disable-default-rendering';

	protected $autoloadLanguage = true;

	private $supportedContexts;

	private $onUserSave = false;

	public function __construct ($subject, $config)
	{
		parent::__construct($subject, $config);

		$this->supportedContexts = array();

		foreach (explode(PHP_EOL, $this->params->get('contexts', 'com_content=article,form' . PHP_EOL . 'com_users=user,profile')) as $entry)
		{
			$parts = explode('=', trim($entry));
			if (count($parts) < 2)
			{
				continue;
			}
			$this->supportedContexts[$parts[0]] = $parts[1];
		}
	}

	public function onAfterRoute ()
	{
		$app = JFactory::getApplication();

		// Only add entries on back end
		if (! $app->isAdmin())
		{
			return;
		}
		$component = $app->input->getCmd('option');

		// Define the component and section of the context to support
		$section = '';
		if ($component == 'com_dpfields' || $component == 'com_categories')
		{
			$context = $app->input->getCmd('context', 'com_content');
			$parts = $this->getParts($context);
			if (! $parts)
			{
				$component = $context;
			}
			else
			{
				$component = $parts[0];
				$section = $parts[1];
			}
		}

		// Only do supported contexts
		if (! key_exists($component, $this->supportedContexts))
		{
			return;
		}

		if (! $section)
		{
			$section = $this->supportedContexts[$component];
			$section = explode(',', $section)[0];
		}

		// Add the fields entry
		JHtmlSidebar::addEntry(JText::_('PLG_SYSTEM_DPFIELDS_FIELDS'), 'index.php?option=com_dpfields&context=' . $component . '.' . $section,
				$app->input->getCmd('option') == 'com_dpfields');
	}

	public function onContentBeforeSave ($context, $item, $isNew)
	{
		$parts = $this->getParts($context);
		if (! $parts)
		{
			return true;
		}
		$context = $parts[0] . '.' . $parts[1];

		// Loading the fields
		$fields = DPFieldsHelper::getFields($context);
		if (! $fields)
		{
			return true;
		}

		// Load the item params
		$params = null;
		if (isset($item->params))
		{
			$params = new Registry();
			$params->loadString($item->params);
			$params = $params->toArray();
		}
		else
		{
			// If the item doesn't have the params attribute loead the values
			// directly from the input
			$data = JFactory::getApplication()->input->post->get('jform', array(), 'array');
			if (key_exists('params', $data))
			{
				$params = $data['params'];
			}
		}

		if (! $params)
		{
			return true;
		}

		// Create the new internal dpfields field
		$dpfields = array();
		foreach ($fields as $field)
		{
			// Only safe the fields with the alias from the data
			if (! key_exists($field->alias, $params))
			{
				continue;
			}

			// Set the param on the dpfields variable
			$dpfields[$field->alias] = $params[$field->alias];

			// Remove it from the params array
			unset($params[$field->alias]);
		}

		$item->_dpfields = $dpfields;

		// Update the cleaned up params
		if (isset($item->params))
		{
			$item->params = json_encode($params);
		}
	}

	public function onContentAfterSave ($context, $item, $isNew)
	{
		$parts = $this->getParts($context);
		if (! $parts)
		{
			return true;
		}
		$context = $parts[0] . '.' . $parts[1];

		// Return if the item has no valid state
		$dpfields = null;
		if (isset($item->_dpfields))
		{
			$dpfields = $item->_dpfields;
		}

		if (! $dpfields)
		{
			return true;
		}

		// Loading the fields
		$fields = DPFieldsHelper::getFields($context);
		if (! $fields)
		{
			return true;
		}

		// Loading the model
		$model = JModelLegacy::getInstance('Field', 'DPFieldsModel', array(
				'ignore_request' => true
		));
		foreach ($fields as $field)
		{
			// Only safe the fields with the alias from the data
			if (! key_exists($field->alias, $dpfields))
			{
				continue;
			}

			$id = null;
			if (isset($item->id))
			{
				$id = $item->id;
			}

			if (! $id)
			{
				continue;
			}

			// Setting the value for the field and the item
			$model->setFieldValue($field->id, $context, $id, $dpfields[$field->alias]);
		}

		return true;
	}

	public function onUserAfterSave ($userData, $isNew, $success, $msg)
	{
		// It is not possible to manipulate the user during save events
		// http://joomla.stackexchange.com/questions/10693/changing-user-group-in-onuserbeforesave-of-user-profile-plugin-doesnt-work

		// Check if data is valid or we are in a recursion
		if (! $userData['id'] || $this->onUserSave || ! $success)
		{
			return true;
		}

		$user = JFactory::getUser($userData['id']);
		$user->params = (string) $user->getParameters();

		// Trigger the events with a real user
		$this->onContentBeforeSave('com_users.user', $user, false);
		$this->onContentAfterSave('com_users.user', $user, false);

		// Save the user with the modifed params
		$this->onUserSave = true;
		$user->setParameters(new Registry($user->params));
		$user->save();
		$this->onUserSave = false;

		return true;
	}

	public function onContentAfterDelete ($context, $item)
	{
		$parts = $this->getParts($context);
		if (! $parts)
		{
			return true;
		}
		$context = $parts[0] . '.' . $parts[1];

		JLoader::import('joomla.application.component.model');
		JModelLegacy::addIncludePath(JPATH_ADMINISTRATOR . '/components/com_dpfields/models', 'DPFieldsModel');
		$model = JModelLegacy::getInstance('Field', 'DPFieldsModel', array(
				'ignore_request' => true
		));
		$model->cleanupValues($context, $item->id);
		return true;
	}

	public function onUserAfterDelete ($user, $succes, $msg)
	{
		$item = new stdClass();
		$item->id = $user['id'];
		return $this->onContentAfterDelete('com_users.user', $item);
	}

	public function onContentPrepareForm (JForm $form, $data)
	{
		// Extracting the component and section
		$parts = $this->getParts($form->getName());
		if (! $parts)
		{
			return true;
		}

		$component = $parts[0];
		$section = $parts[1];

		// Getting the fields
		$fields = DPFieldsHelper::getFields($parts[0] . '.' . $parts[1], $data);
		if (! $fields)
		{
			return true;
		}

		// Creating the dom
		$xml = new DOMDocument('1.0', 'UTF-8');
		$fieldsNode = $xml->appendChild(new DOMElement('form'))->appendChild(new DOMElement('fields'));
		$fieldsNode->setAttribute('name', 'params');
		$fieldsNode->setAttribute('addfieldpath', '/administrator/components/com_dpfields/models/fields');
		$fieldsNode->setAttribute('addrulepath', '/administrator/components/com_dpfields/models/rules');

		// Defining the field set
		$fieldset = $fieldsNode->appendChild(new DOMElement('fieldset'));
		$fieldset->setAttribute('name', 'params');
		$fieldset->setAttribute('name', 'dpfields');
		$fieldset->setAttribute('addfieldpath', '/administrator/components/' . $component . '/models/fields');
		$fieldset->setAttribute('addrulepath', '/administrator/components/' . $component . '/models/rules');

		$lang = JFactory::getLanguage();
		$key = strtoupper($component . '_FIELDS_' . $section . '_LABEL');
		if (! $lang->hasKey($key))
		{
			$key = 'PLG_SYSTEM_DPFIELDS_FIELDS';
		}
		$fieldset->setAttribute('label', JText::_($key));
		$key = strtoupper($component . '_FIELDS_' . $section . '_DESC');
		if ($lang->hasKey($key))
		{
			$fieldset->setAttribute('description', JText::_($key));
		}

		// Looping trough the fields for that context
		foreach ($fields as $field)
		{
			// Creating the XML form data
			$type = DPFieldsHelper::loadTypeObject($field->type, $field->context);
			if ($type === false)
			{
				continue;
			}
			try
			{
				// Rendering the type
				$node = $type->appendXMLFieldTag($field, $fieldset, $form);

				// If the field belongs to a catid but the catid in the data is
				// not known, set the required flag to false on any cuscumstance
				if ((! isset($data->catid) || ! $data->catid) && $field->catid)
				{
					$node->setAttribute('required', 'false');
				}
			}
			catch (Exception $e)
			{
				JFactory::getApplication()->enqueueMessage($e->getMessage(), 'error');
			}
		}

		// Loading the XML fields string into the form
		$form->load($xml->saveXML());

		$model = JModelLegacy::getInstance('Field', 'DPFieldsModel', array(
				'ignore_request' => true
		));
		// Looping trough the fields again to set the value
		if (isset($data->id) && $data->id)
		{
			foreach ($fields as $field)
			{
				$value = $model->getFieldValue($field->id, $field->context, $data->id);
				if (! $value)
				{
					continue;
				}
				// Setting the value on the field
				$form->setValue($field->alias, 'params', $value);
			}
		}

		return true;
	}

	public function onContentPrepareData ($context, $data)
	{
		$parts = $this->getParts($context);
		if (! $parts)
		{
			return;
		}

		if (isset($data->params) && $data->params instanceof Registry)
		{
			$data->params = $data->params->toArray();
		}
	}

	public function onContentBeforeDisplay ($context, $item, $params, $limitstart = 0)
	{
		$parts = $this->getParts($context);
		if (! $parts)
		{
			return '';
		}
		$context = $parts[0] . '.' . $parts[1];

		// Check if we should be hidden
		foreach (DPFieldsHelper::getFields($context, $item) as $field)
		{
			if ($field->alias == self::DEFAULT_HIDE_ALIAS && $field->value)
			{
				return '';
			}
		}
		return JLayoutHelper::render('fields.render',
				array(
						'item' => $item,
						'context' => $context,
						'container' => $params->get('dpfields-container'),
						'container-class' => $params->get('dpfields-container-class')
				), null, array(
						'component' => 'com_dpfields',
						'client' => 0
				));
	}

	public function onContentPrepare ($context, $item)
	{
		$parts = $this->getParts($context);
		if (! $parts)
		{
			return;
		}

		$fields = DPFieldsHelper::getFields($parts[0] . '.' . $parts[1], $item, true);

		// Adding the fields to the object
		$item->dpfields = array();
		foreach ($fields as $field)
		{
			$item->dpfields[$field->alias] = $field->value;
		}

		// If we don't meet all the requirements return
		if (! isset($item->id) || ! $item->id || ! $item->text || ! JString::strpos($item->text, 'dpfields') !== false)
		{
			return true;
		}

		// Count how many times we need to process the fields
		$count = substr_count($item->text, '{{#dpfields');
		for ($i = 0; $i < $count; $i ++)
		{
			// Check for parameters
			preg_match('/{{#dpfields\s*.*?}}/i', $item->text, $starts, PREG_OFFSET_CAPTURE);
			preg_match('/{{\/dpfields}}/i', $item->text, $ends, PREG_OFFSET_CAPTURE);

			// Extract the parameters
			$start = $starts[0][1] + strlen($starts[0][0]);
			$end = $ends[0][1];
			$params = explode(' ', str_replace(array(
					'{{#dpfields',
					'}}'
			), '', $starts[0][0]));

			// Loop trough the params and set them on the model
			foreach ($params as $string)
			{
				$string = trim($string);
				if (! $string)
				{
					continue;
				}

				$paramKey = null;
				$paramValue = null;
				$parts = explode('=', $string);
				if (count($parts) > 0)
				{
					$paramKey = $parts[0];
				}
				if (count($parts) > 1)
				{
					$paramValue = $parts[1];
				}

				if ($paramKey == 'id')
				{
					$paramValue = explode(',', $paramValue);
					JArrayHelper::toInteger($paramValue);
					foreach ($fields as $key => $field)
					{
						if (! in_array($field->id, $paramValue))
						{
							unset($fields[$key]);
						}
					}
				}
			}

			try
			{
				// Load the mustache engine
				JLoader::import('components.com_dpfields.libraries.Mustache.Autoloader', JPATH_ADMINISTRATOR);
				Mustache_Autoloader::register();

				$m = new Mustache_Engine();
				$output = $m->render('{{#dpfields}}' . substr($item->text, $start, $end - $start) . '{{/dpfields}}',
						array(
								'dpfields' => $fields
						));

				// Set the output on the item
				$item->text = substr_replace($item->text, $output, $starts[0][1], $end + 13 - $starts[0][1]);
			}
			catch (Exception $e)
			{
				JFactory::getApplication()->enqueueMessage($e->getMessage(), 'warning');
			}
		}
		return true;
	}

	public function onPrepareFinderContent ($item)
	{
		$section = strtolower($item->layout);
		$tax = $item->getTaxonomy('Type');
		if ($tax)
		{
			foreach ($tax as $context => $value)
			{
				$component = strtolower($context);
				if (strpos($context, 'com_') !== 0)
				{
					$component = 'com_' . $component;
				}

				// Getting the fields for the constructed context
				$fields = DPFieldsHelper::getFields($component . '.' . $section, $item, true);
				if (is_array($fields))
				{
					foreach ($fields as $field)
					{
						// Adding the instructions how to handle the text
						$item->addInstruction(FinderIndexer::TEXT_CONTEXT, $field->alias);

						// Adding the field value as a field
						$item->{$field->alias} = $field->value;
					}
				}
			}
		}
		return true;
	}

	private function getParts ($context)
	{
		$parts = DPFieldsHelper::extract($context);
		if (! $parts)
		{
			return null;
		}

		// Check for supported contexts
		$component = $parts[0];
		if (key_exists($component, $this->supportedContexts))
		{
			$section = $this->supportedContexts[$component];

			// All sections separated with a , after the first ones are aliases
			if (strpos($section, ',') !== false)
			{
				$sectionParts = explode(',', $section);
				if (in_array($parts[1], $sectionParts))
				{
					$parts[1] = $sectionParts[0];
				}
			}
		}
		else if ($parts[1] == 'form')
		{
			// The context is not from a known one, we need to do a lookup
			$db = JFactory::getDbo();
			$db->setQuery('select context from #__dpfields_fields where context like ' . $db->q($parts[0] . '.%') . ' group by context');
			$tmp = $db->loadObjectList();

			if (count($tmp) == 1)
			{
				$parts = explode('.', $tmp[0]->context);
				if (count($parts) < 2)
				{
					return null;
				}
			}
		}

		return $parts;
	}
}