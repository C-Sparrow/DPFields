<?php
/**
 * @package    DPFields
 * @author     Digital Peak http://www.digital-peak.com
 * @copyright  Copyright (C) 2015 - 2015 Digital Peak. All rights reserved.
 * @license    http://www.gnu.org/licenses/gpl.html GNU/GPL
 */
defined('_JEXEC') or die();

$controller = JControllerLegacy::getInstance('DPFields');
$controller->execute(JFactory::getApplication()->input->get('task'));
$controller->redirect();
