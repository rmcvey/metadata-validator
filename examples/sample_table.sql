CREATE TABLE `example_table` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `event_name` varchar(255) NOT NULL COMMENT '{"funcs":{"func": {"n":"str_replace", "params":{"p1":"arch","p2":"e how cool","p3":"@this"}}},"validators":{ "maxlength":"10", "minlength":"2", "pattern":"[0-9]+"}}',
  `city` varchar(255) NOT NULL COMMENT '{"funcs":{"func": {"n":"str_replace", "params":{"p1":"arch","p2":"e how cool","p3":"@this"}}},"validators":{ "maxlength":"14", "minlength":"2", "pattern":"[0-9]+"}}',
  `zip` varchar(10) NOT NULL,
  `date_start` datetime DEFAULT '2009-12-26 00:00:00',
  `date_end` datetime NOT NULL DEFAULT '2040-01-01 00:00:00' COMMENT '{"funcs":{"func":{"n":"date", "params":{"p1":"Y-m-d H:i:s","p2":{"func":{"n":"strtotime", "params":{"p1":"+2 years"}}}}}}}',
  `entered_via` enum('web','csv','email') NOT NULL DEFAULT 'web',
   PRIMARY KEY (`id`)
) ENGINE=MyISAM AUTO_INCREMENT=1 DEFAULT CHARSET=latin1;