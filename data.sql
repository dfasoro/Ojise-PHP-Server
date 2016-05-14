/*
SQLyog Enterprise - MySQL GUI v8.18 
MySQL - 5.5.8-log : Database - new_ojise
*********************************************************************
*/

/*!40101 SET NAMES utf8 */;

/*!40101 SET SQL_MODE=''*/;

/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
CREATE DATABASE /*!32312 IF NOT EXISTS*/`new_ojise` /*!40100 DEFAULT CHARACTER SET latin1 */;

USE `new_ojise`;

/*Table structure for table `device_register` */

DROP TABLE IF EXISTS `device_register`;

CREATE TABLE `device_register` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `access_code` varchar(50) DEFAULT NULL,
  `ojise_key` varchar(50) NOT NULL,
  `date_added` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `NewIndex2` (`ojise_key`),
  UNIQUE KEY `NewIndex1` (`access_code`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=latin1;

/*Table structure for table `item_threads` */

DROP TABLE IF EXISTS `item_threads`;

CREATE TABLE `item_threads` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `item_id` bigint(20) NOT NULL,
  `completed` tinyint(4) NOT NULL DEFAULT '0',
  `thread_number` tinyint(4) DEFAULT NULL,
  `start_pos` bigint(20) DEFAULT NULL,
  `current_size` bigint(20) DEFAULT '0',
  `size` bigint(20) DEFAULT NULL,
  `chunk_serial` int(11) NOT NULL DEFAULT '0',
  `date_spawn` datetime DEFAULT NULL,
  `date_completed` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `NewIndex1` (`item_id`,`thread_number`)
) ENGINE=InnoDB AUTO_INCREMENT=85 DEFAULT CHARSET=latin1;

/*Table structure for table `upload_batches` */

DROP TABLE IF EXISTS `upload_batches`;

CREATE TABLE `upload_batches` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `register_id` bigint(20) NOT NULL,
  `params` mediumtext,
  `batch_threads` tinyint(4) DEFAULT NULL,
  `completed` tinyint(4) NOT NULL DEFAULT '0',
  `merged` tinyint(4) NOT NULL DEFAULT '0',
  `save_result` mediumtext,
  `date_added` datetime NOT NULL,
  `date_completed` datetime DEFAULT NULL,
  `expiry` int(11) unsigned NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1;

/*Table structure for table `upload_items` */

DROP TABLE IF EXISTS `upload_items`;

CREATE TABLE `upload_items` (
  `id` bigint(20) NOT NULL AUTO_INCREMENT,
  `batch_id` bigint(20) NOT NULL,
  `local_id` int(10) NOT NULL,
  `type` varchar(10) NOT NULL COMMENT 'file/data',
  `params` mediumtext,
  `priority` mediumint(8) unsigned DEFAULT NULL,
  `filename` varchar(1000) DEFAULT NULL,
  `size` bigint(20) DEFAULT NULL,
  `completed` tinyint(4) NOT NULL DEFAULT '0',
  `merged` tinyint(4) NOT NULL DEFAULT '0',
  `save_result` mediumtext,
  `date_started` datetime DEFAULT NULL,
  `date_completed` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `NewIndex1` (`batch_id`,`local_id`)
) ENGINE=InnoDB AUTO_INCREMENT=25 DEFAULT CHARSET=latin1;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;
