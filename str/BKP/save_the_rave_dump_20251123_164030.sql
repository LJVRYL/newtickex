PRAGMA foreign_keys=OFF;
BEGIN TRANSACTION;
CREATE TABLE entradas (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nombre TEXT NOT NULL,
            email TEXT NOT NULL,
            fecha_registro TEXT NOT NULL,
            codigo TEXT NOT NULL UNIQUE,
            checked_in INTEGER NOT NULL DEFAULT 0
        , checked_in_at TEXT, tipo TEXT NOT NULL DEFAULT 'desconocido', monto_pagado INTEGER NOT NULL DEFAULT 0, evento_id INTEGER);
INSERT INTO "entradas" VALUES(5,'Leonardo','ljvryl@gmail.com','2025-11-15 19:57:30','28bb0be8da',1,'2025-11-15 20:05:42','desconocido',0,1);
INSERT INTO "entradas" VALUES(6,'Gala','schwartzgala5@gmail.com','2025-11-15 20:08:48','555e1d97f2',1,'2025-11-16 02:16:54','desconocido',0,1);
INSERT INTO "entradas" VALUES(7,'britany+1','brianburet@gmail.com','2025-11-15 20:12:18','ab359e2e86',1,'2025-11-16 00:59:39','desconocido',0,1);
INSERT INTO "entradas" VALUES(9,'Reni +1','ljvryl@gmail.com','2025-11-15 20:35:51','0a55f05fad',1,'2025-11-15 20:43:19','desconocido',0,1);
INSERT INTO "entradas" VALUES(10,'Juan Luna','juanlunita@gmail.com','2025-11-15 20:46:25','b0f440acde',1,'2025-11-15 20:46:57','desconocido',0,1);
INSERT INTO "entradas" VALUES(13,'Camilanas0','Oliverhijo22@gmail.com','2025-11-15 21:01:00','4c9fae7db4',0,NULL,'desconocido',0,1);
INSERT INTO "entradas" VALUES(14,'Nico Saru+1','anothernico@gmail.com','2025-11-15 21:13:38','29568e10ff',0,NULL,'desconocido',0,1);
INSERT INTO "entradas" VALUES(15,'Luis eduardo Rodriguez cordero','camilaayelennaso@gmail.com','2025-11-15 21:22:13','db289ea3dc',0,NULL,'desconocido',0,1);
INSERT INTO "entradas" VALUES(161,'Lalupit','guadalupe.fazzolari@gmail.com','2025-11-15 21:45:08','6887d96e99',1,'2025-11-16 00:37:42','desconocido',0,1);
INSERT INTO "entradas" VALUES(162,'Noe','noeliasabraham@gmail.com','2025-11-15 21:48:13','1ab83264d6',1,'2025-11-16 02:26:55','desconocido',0,1);
INSERT INTO "entradas" VALUES(163,'Noe','noeliasabraham@gmail.com','2025-11-15 21:48:27','b831622b08',1,'2025-11-16 02:26:52','desconocido',0,1);
INSERT INTO "entradas" VALUES(164,'La pichi + 1','pichijimenacarol@gmail.com','2025-11-15 21:48:43','d99e6cfa10',0,NULL,'desconocido',0,1);
INSERT INTO "entradas" VALUES(165,'Lalú','lugoonzalez@gmail.com','2025-11-15 21:50:08','a2e375e8a9',1,'2025-11-16 02:23:58','desconocido',0,1);
INSERT INTO "entradas" VALUES(166,'Maru Teletortugas','emeaerreu@gmail.com','2025-11-15 21:53:26','f1a44313e8',1,'2025-11-16 02:26:28','desconocido',0,1);
INSERT INTO "entradas" VALUES(167,'agustina maria cabral','','2025-11-15 22:02:39','19e0f63bb5',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(168,'Agustina María cabral','','2025-11-15 22:02:39','09fc577c82',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(169,'alan augusto fornari','','2025-11-15 22:02:39','66e5eba1cf',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(170,'axel david cantoni benitez','','2025-11-15 22:02:39','a9248678dd',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(171,'Cache','','2025-11-15 22:02:39','8f66434af8',1,'2025-11-16 03:40:21','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(172,'Cami Vega','','2025-11-15 22:02:39','b6ff957e15',1,'2025-11-16 01:16:28','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(173,'castro kairuz eliana','','2025-11-15 22:02:39','18200d7abc',1,'2025-11-16 02:52:58','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(174,'Chenny','','2025-11-15 22:02:39','d063560ecc',1,'2025-11-16 02:05:31','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(175,'daniel freddy palza','','2025-11-15 22:02:39','0cc2d8ee7f',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(176,'David gastón fagotti','','2025-11-15 22:02:39','911f571c92',1,'2025-11-16 02:55:11','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(177,'facundo varas 1','','2025-11-15 22:02:39','9112b301f2',1,'2025-11-16 01:20:42','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(178,'facundo varas 2','','2025-11-15 22:02:39','b018bd2c06',1,'2025-11-16 01:31:20','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(179,'Ferna Brewer','','2025-11-15 22:02:39','6156f143e9',1,'2025-11-16 01:19:52','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(180,'Frana Zabala','','2025-11-15 22:02:39','bd0f4a0f6a',1,'2025-11-16 01:19:36','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(181,'franco joel roda','','2025-11-15 22:02:39','c39c1e65fd',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(182,'german welchli 1','','2025-11-15 22:02:39','59537c661f',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(183,'german welchli 2','','2025-11-15 22:02:39','5aec183f80',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(184,'Guillermina Catalano','','2025-11-15 22:02:39','86ddf64660',1,'2025-11-16 02:54:32','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(185,'hernan patricio de rosa','','2025-11-15 22:02:39','34fcd21585',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(186,'ivana ostertag','','2025-11-15 22:02:39','b4d7fa3f12',1,'2025-11-16 04:01:15','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(187,'Josefina lucero','','2025-11-15 22:02:39','0d09d42092',1,'2025-11-16 02:25:54','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(188,'lisandro rodriguez cometta','','2025-11-15 22:02:39','014aa572b8',1,'2025-11-16 02:12:11','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(189,'Lore Colmenares','','2025-11-15 22:02:39','390b1f2a9f',1,'2025-11-16 02:05:56','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(190,'lu - kurda - lore 1','','2025-11-15 22:02:39','678431093c',1,'2025-11-16 02:01:09','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(191,'lu - kurda - lore 2','','2025-11-15 22:02:39','fce2b321b7',1,'2025-11-16 02:03:45','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(192,'lu - kurda - lore 3','','2025-11-15 22:02:39','99bd0463ba',1,'2025-11-16 02:25:06','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(193,'Lu Berti','','2025-11-15 22:02:39','d24cc3c20a',1,'2025-11-16 01:19:13','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(194,'Lucas Insaurralde','','2025-11-15 22:02:39','1819e5196b',1,'2025-11-16 02:54:45','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(195,'Luco','','2025-11-15 22:02:39','975e98a872',1,'2025-11-16 02:06:16','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(196,'Mak francinella','','2025-11-15 22:02:39','dae75cccb7',1,'2025-11-16 01:18:44','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(197,'malena del vecchio','','2025-11-15 22:02:39','dd48815cbd',1,'2025-11-16 01:29:11','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(198,'Malena del Vecchio','','2025-11-15 22:02:39','b78af95921',1,'2025-11-16 01:29:08','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(199,'Martina Kogan','','2025-11-15 22:02:39','6bae91eb60',1,'2025-11-16 01:16:01','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(200,'maximiliano javier espinosa','','2025-11-15 22:02:39','12dc3957af',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(201,'Mori Mariani','','2025-11-15 22:02:39','41aab004b0',1,'2025-11-16 02:08:11','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(202,'Nava Gonzalez victor 1','','2025-11-15 22:02:39','9945e09a8c',1,'2025-11-16 03:05:41','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(203,'Nava Gonzalez victor 2','','2025-11-15 22:02:39','2999d75524',1,'2025-11-16 03:05:37','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(204,'Nico H','','2025-11-15 22:02:39','dbe97e2ef4',1,'2025-11-16 01:16:49','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(205,'Quintero salas Luis alejandro','','2025-11-15 22:02:39','d4b9cd9d3a',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(206,'Bernal romero 1','','2025-11-15 22:02:39','98cf0cbc5a',1,'2025-11-16 02:46:35','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(207,'Bernal romero 2','','2025-11-15 22:02:39','8e5c791389',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(208,'ro','','2025-11-15 22:02:39','991d81f680',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(209,'samonta montserrat','','2025-11-15 22:02:39','a02c3b42d8',1,'2025-11-16 01:22:39','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(210,'Santiago Luis damiani','','2025-11-15 22:02:39','1123496a8b',1,'2025-11-16 01:53:45','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(211,'sorbo de vino','','2025-11-15 22:02:39','d5dba8bdee',1,'2025-11-16 04:10:09','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(212,'urue a micaela lis','','2025-11-15 22:02:39','121ae7df15',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(213,'varela facundo ramiro 1','','2025-11-15 22:02:39','25478f308f',1,'2025-11-16 01:54:49','ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(214,'varela facundo ramiro 2','','2025-11-15 22:02:39','9c8fac1853',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(215,'varela facundo ramiro 3','','2025-11-15 22:02:39','93db2702c4',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(216,'agustin perez','','2025-11-15 22:02:40','e52ecb4e80',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(217,'Agustín Rodríguez 1','','2025-11-15 22:02:40','b4965d917a',1,'2025-11-16 02:37:19','FREE',0,1);
INSERT INTO "entradas" VALUES(218,'Agustín Rodríguez 2','','2025-11-15 22:02:40','a906374f57',1,'2025-11-16 02:37:13','FREE',0,1);
INSERT INTO "entradas" VALUES(219,'agustina cabral','','2025-11-15 22:02:40','d638250ce7',1,'2025-11-16 03:16:24','FREE',0,1);
INSERT INTO "entradas" VALUES(220,'Agustina Muñiz','','2025-11-15 22:02:40','8d7cc20043',1,'2025-11-16 01:15:30','FREE',0,1);
INSERT INTO "entradas" VALUES(221,'alejandro gustavo','','2025-11-15 22:02:40','7935c320a0',1,'2025-11-16 02:12:57','FREE',0,1);
INSERT INTO "entradas" VALUES(222,'andy jules jota','','2025-11-15 22:02:40','b3ad5f63cc',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(223,'azzurra','','2025-11-15 22:02:40','4d3128cdcf',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(224,'berchi 1','','2025-11-15 22:02:40','919e5d862b',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(225,'berchi 2','','2025-11-15 22:02:40','11787c0963',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(226,'bicho sonoro','','2025-11-15 22:02:40','9f6b1f66a1',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(227,'cami','','2025-11-15 22:02:40','1908ad1b07',1,'2025-11-16 02:13:26','FREE',0,1);
INSERT INTO "entradas" VALUES(228,'campe','','2025-11-15 22:02:40','fcc6a6056e',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(229,'cuax cristian','','2025-11-15 22:02:40','6c563b84e4',1,'2025-11-16 01:35:01','FREE',0,1);
INSERT INTO "entradas" VALUES(230,'Daian Gulvinowiez','','2025-11-15 22:02:40','48916d0cd5',1,'2025-11-16 01:15:48','FREE',0,1);
INSERT INTO "entradas" VALUES(231,'daro','','2025-11-15 22:02:40','212dc1f0c2',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(232,'demi','','2025-11-15 22:02:40','e2518e1ef2',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(233,'eddie garcia','','2025-11-15 22:02:40','fd16708dcb',1,'2025-11-16 01:52:19','FREE',0,1);
INSERT INTO "entradas" VALUES(234,'eliana castro kairuz','','2025-11-15 22:02:40','c2359ccccb',1,'2025-11-16 02:52:51','FREE',0,1);
INSERT INTO "entradas" VALUES(235,'emilio salazar','','2025-11-15 22:02:40','025d366df8',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(236,'fede kawill','','2025-11-15 22:02:40','654b0af87d',1,'2025-11-16 03:20:16','FREE',0,1);
INSERT INTO "entradas" VALUES(237,'francisco macfarlane','','2025-11-15 22:02:40','216fe4dc72',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(238,'franquito','','2025-11-15 22:02:40','cbe179f577',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(239,'galactica azul','','2025-11-15 22:02:40','96ec7b72f6',1,'2025-11-16 02:28:39','FREE',0,1);
INSERT INTO "entradas" VALUES(240,'gonzalo andres','','2025-11-15 22:02:40','301b941340',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(241,'grecia talavera','','2025-11-15 22:02:40','5c2fca3798',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(242,'guida lopez (ella)','','2025-11-15 22:02:40','42f9437295',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(243,'ivan kamenskii','','2025-11-15 22:02:40','b01de64b91',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(244,'ivan olson','','2025-11-15 22:02:40','3c9dd7900e',1,'2025-11-16 01:34:48','FREE',0,1);
INSERT INTO "entradas" VALUES(245,'jaqueline grajales','','2025-11-15 22:02:40','8030e311e5',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(246,'javi shpsfht','','2025-11-15 22:02:40','799be8c845',1,'2025-11-16 01:51:05','FREE',0,1);
INSERT INTO "entradas" VALUES(247,'jhosten','','2025-11-15 22:02:40','068a42a164',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(248,'joana amarilla','','2025-11-15 22:02:40','5eb94e6eaa',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(249,'joaquito','','2025-11-15 22:02:40','b03d51c451',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(250,'juani HE CLOUD','','2025-11-15 22:02:40','54569f1660',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(251,'juan miguel','','2025-11-15 22:02:40','d33f29e8a8',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(252,'juli','','2025-11-15 22:02:40','fa429d2522',1,'2025-11-16 00:47:11','FREE',0,1);
INSERT INTO "entradas" VALUES(253,'Julián Felipe','','2025-11-15 22:02:40','eb58a7e0c5',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(254,'julis perelov 1','','2025-11-15 22:02:40','3f056246ea',1,'2025-11-16 04:10:05','FREE',0,1);
INSERT INTO "entradas" VALUES(255,'julis perelov 2','','2025-11-15 22:02:40','7c822fb4ae',1,'2025-11-16 04:10:10','FREE',0,1);
INSERT INTO "entradas" VALUES(256,'kai','','2025-11-15 22:02:40','e7548b6554',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(257,'kai martinez (cualquier prenombre)','','2025-11-15 22:02:40','70079f7a9b',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(258,'keka y luis','','2025-11-15 22:02:40','693a2e0d94',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(259,'leandro vannucci','','2025-11-15 22:02:40','c0b249824d',1,'2025-11-16 01:35:22','FREE',0,1);
INSERT INTO "entradas" VALUES(260,'leandro vigo','','2025-11-15 22:02:40','48fa8f96f1',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(261,'lei','','2025-11-15 22:02:40','d08f473af6',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(262,'lucas las heras','','2025-11-15 22:02:40','6926bc155b',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(263,'manu nube','','2025-11-15 22:02:40','8990f5df0a',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(264,'marta','','2025-11-15 22:02:40','fb52cadbbf',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(265,'martin','','2025-11-15 22:02:40','3b7cc48753',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(266,'martu','','2025-11-15 22:02:40','7031a7e8c2',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(267,'mauro dostal','','2025-11-15 22:02:40','83e34ac298',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(268,'maxi jam','','2025-11-15 22:02:40','50ac3d0dde',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(269,'micaela urueña','','2025-11-15 22:02:40','10dc068e2e',1,'2025-11-16 01:08:00','FREE',0,1);
INSERT INTO "entradas" VALUES(270,'miguel demaria','','2025-11-15 22:02:40','34525b3d6f',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(271,'milagros alegre','','2025-11-15 22:02:40','9f984b7d30',1,'2025-11-16 01:54:00','FREE',0,1);
INSERT INTO "entradas" VALUES(272,'montenegro americo','','2025-11-15 22:02:40','fb7265b914',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(273,'montserrat samonta','','2025-11-15 22:02:40','b997786443',1,'2025-11-16 01:22:31','FREE',0,1);
INSERT INTO "entradas" VALUES(274,'mora y juli','','2025-11-15 22:02:40','6f30805247',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(275,'natalia kriger','','2025-11-15 22:02:40','323baa45bd',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(276,'nico birras','','2025-11-15 22:02:40','b618b2b561',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(277,'pablo aguilar 1','','2025-11-15 22:02:40','5dcbc52c4e',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(278,'pablo aguilar 2','','2025-11-15 22:02:40','cae073b1bd',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(279,'pit pedro','','2025-11-15 22:02:40','311681e6ad',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(280,'renan ferrer','','2025-11-15 22:02:40','a17af39e11',1,'2025-11-16 00:45:49','FREE',0,1);
INSERT INTO "entradas" VALUES(281,'rgb prod 1','','2025-11-15 22:02:40','6346134409',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(282,'rgb prod 2','','2025-11-15 22:02:40','17835e2afc',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(283,'rgb prod 3','','2025-11-15 22:02:40','05e5845731',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(284,'sarmiento 1','','2025-11-15 22:02:40','a5fc012415',1,'2025-11-16 01:50:36','FREE',0,1);
INSERT INTO "entradas" VALUES(285,'sarmiento 2','','2025-11-15 22:02:40','ad03aa73ce',1,'2025-11-16 01:50:39','FREE',0,1);
INSERT INTO "entradas" VALUES(286,'sarmiento 3','','2025-11-15 22:02:40','7c3ad12426',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(287,'saigg','','2025-11-15 22:02:40','815073233c',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(288,'soberbio','','2025-11-15 22:02:40','0b702be8e4',1,'2025-11-16 01:17:17','FREE',0,1);
INSERT INTO "entradas" VALUES(289,'sofia bauhaus','','2025-11-15 22:02:40','b6bdfcd4f9',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(290,'sofia chofiaa','','2025-11-15 22:02:40','69b6788a95',1,'2025-11-16 03:20:46','FREE',0,1);
INSERT INTO "entradas" VALUES(291,'tanzi 1','','2025-11-15 22:02:40','ae44462db2',1,'2025-11-16 01:55:54','FREE',0,1);
INSERT INTO "entradas" VALUES(292,'tanzi 2','','2025-11-15 22:02:40','1295587fda',1,'2025-11-16 01:55:57','FREE',0,1);
INSERT INTO "entradas" VALUES(293,'tanzi 3','','2025-11-15 22:02:40','8817d32b86',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(294,'tanzi 4','','2025-11-15 22:02:40','839e4ed1ef',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(295,'tanzi 5','','2025-11-15 22:02:40','06a7de2bdf',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(296,'tena','','2025-11-15 22:02:40','51afba8b15',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(297,'tiago','','2025-11-15 22:02:40','1e1439ed80',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(298,'toti','','2025-11-15 22:02:40','b13c4cbd96',1,'2025-11-16 00:46:39','FREE',0,1);
INSERT INTO "entradas" VALUES(299,'verdun ulises','','2025-11-15 22:02:40','2000210bdb',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(300,'wally','','2025-11-15 22:02:40','a923e669a7',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(301,'yanina giovannetti','','2025-11-15 22:02:40','0c316c5c2d',1,'2025-11-16 01:35:31','FREE',0,1);
INSERT INTO "entradas" VALUES(302,'maxi jam friends','','2025-11-15 22:02:41','399cea897a',0,NULL,'PUERTA_10000',0,1);
INSERT INTO "entradas" VALUES(303,'Agustín Rodríguez friends','','2025-11-15 22:02:41','bbd14340ef',1,'2025-11-16 02:37:05','PUERTA_10000',0,1);
INSERT INTO "entradas" VALUES(304,'Darack Muchile','','2025-11-15 22:02:41','07cb9ed461',0,NULL,'PUERTA_10000',0,1);
INSERT INTO "entradas" VALUES(305,'Julián Felipe friends','','2025-11-15 22:02:41','c9fe7b5a3b',0,NULL,'PUERTA_10000',0,1);
INSERT INTO "entradas" VALUES(306,'Matias Ciocca','','2025-11-15 22:02:41','af5bcd9f4d',0,NULL,'PUERTA_10000',0,1);
INSERT INTO "entradas" VALUES(307,'saigg friends','','2025-11-15 22:02:41','835afd0e5d',0,NULL,'PUERTA_10000',0,1);
INSERT INTO "entradas" VALUES(308,'Sasha ( Rusia )','','2025-11-15 22:02:41','65149844fa',0,NULL,'PUERTA_15000',0,1);
INSERT INTO "entradas" VALUES(309,'Francesca fechino','','2025-11-15 22:02:41','342d005721',0,NULL,'OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(310,'franco roda','','2025-11-15 22:02:41','86acea4903',0,NULL,'OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(311,'Cecilia Gómez','','2025-11-15 22:02:41','c453092de2',1,'2025-11-16 01:54:40','OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(312,'Antonella Marabotto','','2025-11-15 22:02:41','584016d1fb',1,'2025-11-16 01:08:14','OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(313,'Andi sigalov','','2025-11-15 22:02:41','4b6fc00eea',1,'2025-11-16 03:16:47','OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(314,'Fernanda posdata 1','','2025-11-15 22:02:41','0fa8188a9a',1,'2025-11-16 01:45:23','OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(315,'Fernanda posdata 2','','2025-11-15 22:02:41','f776297574',1,'2025-11-16 01:45:25','OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(316,'Fernanda posdata 3','','2025-11-15 22:02:41','06998c6977',1,'2025-11-16 01:45:27','OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(317,'Delfina quintans','','2025-11-15 22:02:41','97a5345c1c',1,'2025-11-16 03:14:36','OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(318,'Hernán 1','','2025-11-15 22:02:41','b7cd4294d0',1,'2025-11-16 02:14:07','OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(319,'Hernán 2','','2025-11-15 22:02:41','4d46ea5771',1,'2025-11-16 02:14:12','OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(320,'Hernán 3','','2025-11-15 22:02:41','32e487050f',1,'2025-11-16 02:14:17','OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(321,'Juan Camilo Martínez','','2025-11-15 22:02:41','72cb2aa189',0,NULL,'OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(322,'Germán Welchli','','2025-11-15 22:02:41','1bea1cf0c1',1,'2025-11-16 01:56:52','OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(323,'Paola Lalia','','2025-11-15 22:02:41','89030df719',1,'2025-11-16 01:56:41','OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(324,'Romina gigena','','2025-11-15 22:02:41','0cd0cd09f1',1,'2025-11-16 01:29:20','OTRO_NOMBRE',0,1);
INSERT INTO "entradas" VALUES(325,'Agustin','agusperaltamx@gmail.com','2025-11-15 22:04:46','4f9eb4f9cc',0,NULL,'desconocido',0,1);
INSERT INTO "entradas" VALUES(326,'nicole legendre','nikki.lunar.93@gmail.com','2025-11-15 22:14:59','d3ea4e2755',0,NULL,'desconocido',0,1);
INSERT INTO "entradas" VALUES(328,'Hernan patricio de rosa','','2025-11-15 22:40:55','16e687891e',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(329,'Kil','','2025-11-15 22:40:55','e982f6b905',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(330,'Ivana rivarola','','2025-11-15 22:40:55','ca254713bf',0,NULL,'ANTICIPADA',0,1);
INSERT INTO "entradas" VALUES(331,'Mariana Villamarín','','2025-11-15 22:40:55','bb7444c176',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(332,'Lu bauchi','','2025-11-15 22:40:56','abd98e4a2b',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(333,'Pistolera','','2025-11-15 22:40:56','1e9cadf25d',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(334,'Uma Rafecas','','2025-11-15 22:40:56','e2ca7f3072',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(335,'Poseso','','2025-11-15 22:40:56','33e60b4134',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(336,'Panchito','','2025-11-15 22:40:56','83326302ed',1,'2025-11-16 03:18:27','FREE',0,1);
INSERT INTO "entradas" VALUES(337,'Panchito','','2025-11-15 22:40:56','1df84dadbe',1,'2025-11-16 03:18:24','FREE',0,1);
INSERT INTO "entradas" VALUES(338,'Ivan','','2025-11-15 22:40:56','116dde4fce',1,'2025-11-16 03:56:44','FREE',0,1);
INSERT INTO "entradas" VALUES(339,'Lautaro','','2025-11-15 22:40:56','47a22eeb92',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(340,'Jhonatan f','','2025-11-15 22:40:56','6b99fe6fdb',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(341,'Civil Hate','','2025-11-15 22:40:56','6d9fc23073',0,NULL,'PUERTA_10000',0,1);
INSERT INTO "entradas" VALUES(342,'Civil Hate','','2025-11-15 22:40:56','52dd352fdd',1,'2025-11-16 01:28:48','PUERTA_10000',0,1);
INSERT INTO "entradas" VALUES(343,'Guada nuñez','lupecamus@hotmail.com','2025-11-15 23:19:07','7216d1258f',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(344,'Nicolás González','tommahawkng@hotmail.com','2025-11-15 23:39:36','f0b3be6ed2',1,'2025-11-16 01:32:15','FREE',0,1);
INSERT INTO "entradas" VALUES(345,'soso','sofiasolisdc@gmail.com','2025-11-15 23:44:26','c41e8b19b9',1,'2025-11-16 01:24:54','FREE',0,1);
INSERT INTO "entradas" VALUES(346,'Sebastián serro','sofiasolisdc@gmail.com','2025-11-15 23:44:57','a6ae009690',1,'2025-11-16 03:25:55','FREE',0,1);
INSERT INTO "entradas" VALUES(347,'Matías','matiaspolrivas111@gmail.com','2025-11-15 23:47:04','1cce088a1a',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(348,'Rocio','rocioailensoto6@gmail.com','2025-11-15 23:53:05','121f37da1f',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(349,'Natalia vlacq','nata_07_@hotmail.com','2025-11-16 00:13:29','bfad1c4173',1,'2025-11-16 03:32:58','FREE',0,1);
INSERT INTO "entradas" VALUES(350,'Pablo gil','nata_07_@hotmail.com','2025-11-16 00:13:44','fd13989418',1,'2025-11-16 03:32:43','FREE',0,1);
INSERT INTO "entradas" VALUES(351,'Sofia','sofia.salatino@bue.edu.ar','2025-11-16 00:13:56','9b18914d76',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(352,'Rocio','rocioailensoto6@gmail.com','2025-11-16 00:14:26','21ec05c456',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(353,'Ro','rosanaesquivel24@gmail.com','2025-11-16 00:21:29','fa23e4f6ff',1,'2025-11-16 03:11:31','FREE',0,1);
INSERT INTO "entradas" VALUES(354,'Deborah Grant','grantozzy@hotmail.com','2025-11-16 00:35:18','b2ba4c008c',1,'2025-11-16 01:23:25','FREE',0,1);
INSERT INTO "entradas" VALUES(355,'JULIETA Robledo','julietarobledo.ch@gmail.com','2025-11-16 00:36:15','e1fbaa8ede',1,'2025-11-16 01:32:02','FREE',0,1);
INSERT INTO "entradas" VALUES(356,'Julieta Robledo','julietarobledo.ch@gmail.com','2025-11-16 00:36:37','0c5571d3ed',1,'2025-11-16 01:31:56','FREE',0,1);
INSERT INTO "entradas" VALUES(357,'Malena Mastrangelo','julietarobledo.ch@gmail.com','2025-11-16 00:37:07','54febd58c3',1,'2025-11-16 02:08:22','FREE',0,1);
INSERT INTO "entradas" VALUES(358,'Milena Loguercio +1','mileloguercio@gmail.com','2025-11-16 00:37:51','71d4b8df56',1,'2025-11-16 03:00:07','FREE',0,1);
INSERT INTO "entradas" VALUES(359,'Violeta','violetarosello@gmail.com','2025-11-16 00:50:26','40a23ca953',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(360,'Lucas','lopezluqui@gmail.com','2025-11-16 00:51:01','6d9f06c55f',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(361,'Daniel','soyelbascu@gmail.com','2025-11-16 00:56:01','72640a6bd6',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(362,'Guille y german','deancassady925@gmail.com','2025-11-16 01:06:15','6b85cba1a2',1,'2025-11-16 01:25:37','FREE',0,1);
INSERT INTO "entradas" VALUES(363,'Fede Godoy','fedegodoy@gmail.com','2025-11-16 01:08:44','44fa61120a',1,'2025-11-16 03:10:01','FREE',0,1);
INSERT INTO "entradas" VALUES(364,'Noe','noeliasabraham@gmail.com','2025-11-16 01:16:27','cf8ae94351',1,'2025-11-17 19:06:40','FREE',0,1);
INSERT INTO "entradas" VALUES(365,'Marco Linares','marcolm89@gmail.com','2025-11-16 01:19:08','5e7aec169b',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(366,'Alan Silva','alanss87@gmail.com','2025-11-16 01:19:29','e7f51a0bfe',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(367,'Matias','aguirrematiasa@live.com.ar','2025-11-16 05:04:37','6bc295334d',0,NULL,'FREE',0,1);
INSERT INTO "entradas" VALUES(375,'Nico','tommawkng@hotmail.com','2025-11-17 00:45:13','73062f03ac',1,'2025-11-17 19:33:36','FREE',0,1);
CREATE TABLE entradas_eliminadas (
  id INTEGER,
  nombre TEXT,
  email TEXT,
  fecha_registro TEXT,
  codigo TEXT,
  checked_in INTEGER,
  checked_in_at TEXT,
  tipo TEXT,
  monto_pagado INTEGER,
  deleted_at TEXT
);
INSERT INTO "entradas_eliminadas" VALUES(11,'Brian Buret','brianburet@gmail.com','2025-11-15 20:47:29','feb8e41e60',0,NULL,'desconocido',0,'2025-11-16T21:31:04-03:00');
INSERT INTO "entradas_eliminadas" VALUES(373,'Prueba después de cambiar php.ini correcto','ljvryl@gmail.com','2025-11-16 22:52:58','ad888ad1f8',0,NULL,'FREE',0,'2025-11-17T11:56:41-03:00');
INSERT INTO "entradas_eliminadas" VALUES(371,'Prueba CURL','ljvryl@gmail.com','2025-11-16 22:19:50','b8f89335be',0,NULL,'FREE',0,'2025-11-17T11:56:44-03:00');
INSERT INTO "entradas_eliminadas" VALUES(372,'Prueba CURL 4','ljvryl@gmail.com','2025-11-16 22:33:37','40e711823c',0,NULL,'FREE',0,'2025-11-17T11:56:48-03:00');
INSERT INTO "entradas_eliminadas" VALUES(370,'Leito','ljvryl@gmail.com','2025-11-16 22:16:03','7ff871bb35',0,NULL,'FREE',0,'2025-11-17T11:56:52-03:00');
INSERT INTO "entradas_eliminadas" VALUES(374,'Prueba REG+CLI','ljvryl@gmail.com','2025-11-17 00:06:39','aa8e931649',0,NULL,'FREE',0,'2025-11-17T11:56:59-03:00');
INSERT INTO "entradas_eliminadas" VALUES(376,'Prueba Registro','ljvryl@gmail.com','2025-11-17 00:50:32','19e7440d92',0,NULL,'FREE',0,'2025-11-17T14:39:05-03:00');
CREATE TABLE usuarios_admin (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password TEXT NOT NULL,
  rol TEXT NOT NULL,
  activo INTEGER NOT NULL DEFAULT 1
, tipo_global TEXT NOT NULL DEFAULT 'admin_evento', rol_evento TEXT DEFAULT NULL, nombre TEXT, email TEXT, dni TEXT, cbu TEXT, avatar_filename TEXT, creado_por_admin_id INTEGER, evento_id INTEGER);
INSERT INTO "usuarios_admin" VALUES(1,'puerta','savetherave','puerta',1,'staff_evento','puerta',NULL,NULL,NULL,NULL,NULL,2,1);
INSERT INTO "usuarios_admin" VALUES(2,'AdminSTR','savetherave69','admin',1,'admin_evento','admin_STR',NULL,NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO "usuarios_admin" VALUES(3,'SuperAdmin','Termidor3win3#','admin',1,'super_admin',NULL,NULL,NULL,NULL,NULL,NULL,NULL,NULL);
INSERT INTO "usuarios_admin" VALUES(6,'puerta2','savetherave','staff',1,'staff_evento','puerta',NULL,NULL,NULL,NULL,NULL,2,1);
INSERT INTO "usuarios_admin" VALUES(7,'puerta3','test123','puerta',1,'staff_evento','puerta',NULL,NULL,NULL,NULL,NULL,2,1);
CREATE TABLE eventos (
  id                 INTEGER PRIMARY KEY AUTOINCREMENT,
  nombre             TEXT NOT NULL,
  slug               TEXT NOT NULL UNIQUE,   -- ej: "str", "retro"
  descripcion        TEXT,
  flyer_filename     TEXT,                   -- nombre de archivo en disco
  fecha_desde        TEXT,                   -- ISO (YYYY-MM-DD o YYYY-MM-DD HH:MM)
  fecha_hasta        TEXT,
  creado_por_admin_id INTEGER,
  creado_en          TEXT NOT NULL,
  actualizado_en     TEXT
);
INSERT INTO "eventos" VALUES(1,'Save The Rave','str','Evento Save The Rave original','flyer-save-the-rave.png',NULL,NULL,2,'2025-11-20 20:02:48',NULL);
INSERT INTO "eventos" VALUES(2,'rincon 1330','rincon','rincon 1330','event_flyers/1763682945_Tarjetas_fin_de_a__o.png','2025-06-21',NULL,NULL,'2025-11-20 23:55:45',NULL);
CREATE TABLE tipos_entrada (
  id                   INTEGER PRIMARY KEY AUTOINCREMENT,
  evento_id            INTEGER NOT NULL,
  nombre               TEXT NOT NULL,  -- ej: "General FREE", "Early Bird"
  tipo                 TEXT NOT NULL,  -- 'free' o 'paga'
  precio               INTEGER NOT NULL DEFAULT 0,  -- en tu unidad (ej: pesos)
  cantidad_total       INTEGER NOT NULL DEFAULT 0,
  cantidad_disponible  INTEGER NOT NULL DEFAULT 0,
  hora_limite          TEXT,        -- texto libre: "hasta las 02:00", etc.
  reglas_precio        TEXT,        -- JSON / descripción de tandas, a futuro
  FOREIGN KEY(evento_id) REFERENCES eventos(id)
);
INSERT INTO "tipos_entrada" VALUES(1,2,'free','free',0,1500,1500,NULL,NULL);
DELETE FROM sqlite_sequence;
INSERT INTO "sqlite_sequence" VALUES('entradas',376);
INSERT INTO "sqlite_sequence" VALUES('usuarios_admin',7);
INSERT INTO "sqlite_sequence" VALUES('eventos',2);
INSERT INTO "sqlite_sequence" VALUES('tipos_entrada',1);
COMMIT;
