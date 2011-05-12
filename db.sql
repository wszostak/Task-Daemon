CREATE TABLE IF NOT EXISTS `tasks` (
  `task_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `route` varchar(50) NOT NULL,
  `uri` text NOT NULL,
  `active` tinyint(1) unsigned NOT NULL DEFAULT '1',
  `priority` tinyint(2) unsigned NOT NULL DEFAULT '5',
  `recurring` int(8) unsigned NOT NULL COMMENT 'Time delay between recurring',
  `pid` smallint(5) unsigned NOT NULL,
  `created` int(10) unsigned NOT NULL,
  `nextrun` int(10) unsigned NOT NULL,
  `lastrun` int(10) unsigned NOT NULL,
  `fail_on_error` tinyint(1) unsigned NOT NULL DEFAULT '0',
  `failed` int(10) unsigned NOT NULL,
  `failed_msg` text NOT NULL,
  PRIMARY KEY (`task_id`),
  KEY `nextrun` (`nextrun`),
  KEY `active` (`active`),
  KEY `pid` (`pid`)
) ENGINE=InnoDB  DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
