<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->get('users/(:num)', 'Users::show/$1');
$routes->post('users', 'Users::create');
$routes->resource('photos');
$routes->group('admin', ['filter' => 'auth'], static function ($routes) {
    $routes->get('dashboard', 'Admin::index');
});
$routes->setAutoRoute(true);
$routes->addPlaceholder('uuid', '[0-9a-f-]{36}');
$routes->map(['legacy' => 'Legacy::index']);
