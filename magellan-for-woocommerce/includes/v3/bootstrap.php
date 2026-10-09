<?php
namespace Magellan\V3;
defined('ABSPATH') || exit;
foreach (['Protocol','Config','Outbox','Recovery','Capture','Browser','Admin','Privacy'] as $module) { require_once __DIR__ . '/' . $module . '.php'; }
Outbox::init();
Recovery::init();
Admin::init();
if (Config::active()) { Capture::init(); Browser::init(); Privacy::init(); }
