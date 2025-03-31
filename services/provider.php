<?php

/*
 * @package    Atom-S ReConnect
 * @version    __DEPLOY_VERSION__
 * @author     Atom-S - atom-s.com
 * @copyright  Copyright (c) 2017 - 2024 Atom-S LLC. All rights reserved.
 * @license    GNU/GPL license: https://www.gnu.org/copyleft/gpl.html
 * @link       https://atom-s.com
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use Joomla\Plugin\Jatoms\Tinkoff\Extension\Tinkoff;

return new class implements ServiceProviderInterface {

	/**
	 * Registers the service provider with a DI container.
	 *
	 * @param   Container  $container  The DI container.
	 *
	 * @since   __DEPLOY_VERSION__
	 */
	public function register(Container $container)
	{
		$container->set(PluginInterface::class,
			function (Container $container) {
				$plugin  = \Joomla\CMS\Plugin\PluginHelper::getPlugin('jatoms', 'tinkoff');
				$subject = $container->get(DispatcherInterface::class);

				$plugin = new Tinkoff($subject, (array) $plugin);
				$plugin->setApplication(Factory::getApplication());

				return $plugin;
			}
		);
	}
};
