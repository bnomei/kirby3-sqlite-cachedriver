# Feather – Kirby 3 SQLite Cache-Driver

![Release](https://flat.badgen.net/packagist/v/bnomei/kirby3-sqlite-cachedriver?color=ae81ff)
![Downloads](https://flat.badgen.net/packagist/dt/bnomei/kirby3-sqlite-cachedriver?color=272822)
[![Build Status](https://flat.badgen.net/travis/bnomei/kirby3-sqlite-cachedriver)](https://travis-ci.com/bnomei/kirby3-sqlite-cachedriver)
[![Coverage Status](https://flat.badgen.net/coveralls/c/github/bnomei/kirby3-sqlite-cachedriver)](https://coveralls.io/github/bnomei/kirby3-sqlite-cachedriver) 
[![Maintainability](https://flat.badgen.net/codeclimate/maintainability/bnomei/kirby3-sqlite-cachedriver)](https://codeclimate.com/github/bnomei/kirby3-sqlite-cachedriver) 
[![Twitter](https://flat.badgen.net/badge/twitter/bnomei?color=66d9ef)](https://twitter.com/bnomei)

SQLite
## Commercial Usage

This plugin is free (MIT license) but if you use it in a commercial project please consider to
- [make a donation 🍻](https://www.paypal.me/bnomei/10) or
- [buy me ☕](https://buymeacoff.ee/bnomei) or
- [buy a Kirby license using this affiliate link](https://a.paddle.com/v2/click/1129/35731?link=1170)

## Installation

- unzip [master.zip](https://github.com/bnomei/kirby3-sqlite-cachedriver/archive/master.zip) as folder `site/plugins/kirby3-sqlite-cachedriver` or
- `git submodule add https://github.com/bnomei/kirby3-sqlite-cachedriver.git site/plugins/kirby3-sqlite-cachedriver` or
- `composer require bnomei/kirby3-sqlite-cachedriver`

## Why

### File < SQLite < Memcached < Redis

Kirby ships with built in support for File and Memcached Cache Drivers. I created a [Redis Cache Driver](https://github.com/bnomei/kirby3-redis-cachedriver) which is imho best suited for larger caches. If your hosting does not support Memcached or Redis your next best choice is this SQLite Cache Driver.

### 2 is enough and about 35% faster

Let's imaging this typical scenario: During a single pageview you need to access, 100 cached values. Some of them already exist, some not, some need to be refreshed and yet others need to be deleted. 
With a File Cache this would cause at least 100 filesystem operations in total. Using this SQLite Cache you will have only 1 file read and maybe 1 file write per pageview no matter how many values you get, update or remove. ✌️
But reading and writing data to SQLite is not instantaneous, so you it will be [at least 35% faster](https://www.hwaci.com/sw/sqlite/fasterthanfs.html).

## Usage 

### Cache methods

```php
$cache = \Bnomei\SQLiteCache::singleton(); // or
$cache = feather();

$cache->set('key', 'value', $expireInMinutes);
$value = feather()->get('key', $default);

feather()->remove('key');
feather()->flush();
```

### Benchmark

```php
feather()->benchmark(1000);
```

```shell script
sqlite : 0.075334072113037
file : 0.11837792396545
```

> ATTENTION: This will create and remove a lot of cache files and sqlite entries

### No cache when debugging

When Kirbys global debug config is set to `true` the complete plugin cache will be flushed and no caches will be created. This will make you live easier – trust me.

### How to use Feather with Lapse

You need to set the cache driver for the [lapse plugin](https://github.com/bnomei/kirby3-lapse) to `sqlite`.

**site/config/config.php**
```php
<?php
return [
    'bnomei.lapse.cache' => ['type' => 'sqlite'],
    //... other options
];
```

### Setup Content-File Cache

You can use a [seperate plugin](https://github.com/bnomei/kirby3-page-sqlite) to create [Page-Models](https://getkirby.com/docs/guide/templates/page-models) and extend the `\Bnomei\SQLitePage` class. That plugin does not require this plugin to be installed. It will read and write a **copy** of your Content-File to and from a seperate SQLite database. It will also automatically track modification of your content to keep it up to date.

### Pragmas

The plugin comes with aggressive defaults for SQLite Pragmas to optimize for performance. You can change these in the settings if you need to.

## Dependencies

- PHP SQLite extension

## Disclaimer

This plugin is provided "as is" with no guarantee. Use it at your own risk and always test it yourself before using it in a production environment. If you find any issues, please [create a new issue](https://github.com/bnomei/kirby3-sqlite-cachedriver/issues/new).

## License

[MIT](https://opensource.org/licenses/MIT)

It is discouraged to use this plugin in any project that promotes racism, sexism, homophobia, animal abuse, violence or any other form of hate speech.
