<?php

namespace Alnv\GenaiBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;

class AlnvGenaiExtension extends Extension
{

    public function load(array $configs, ContainerBuilder $container)
    {

        $objLoader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../Resources/config'));
        $objLoader->load('services.yml');
    }
}