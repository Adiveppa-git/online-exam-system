-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: online_exam_system
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `exam_violations`
--

DROP TABLE IF EXISTS `exam_violations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exam_violations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `violation_count` int(11) DEFAULT 0,
  `auto_submitted` tinyint(1) DEFAULT 0,
  `last_violation` timestamp NOT NULL DEFAULT current_timestamp(),
  `violation_time` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_exam_attempt` (`student_id`,`exam_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exam_violations`
--

LOCK TABLES `exam_violations` WRITE;
/*!40000 ALTER TABLE `exam_violations` DISABLE KEYS */;
/*!40000 ALTER TABLE `exam_violations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `exams`
--

DROP TABLE IF EXISTS `exams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exams` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(150) NOT NULL,
  `duration` int(11) NOT NULL COMMENT 'Minutes',
  `total_marks` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `marks_per_question` int(11) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=26 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `exams`
--

LOCK TABLES `exams` WRITE;
/*!40000 ALTER TABLE `exams` DISABLE KEYS */;
INSERT INTO `exams` VALUES (24,'IA 2',1,0,'2026-02-13 13:35:31',1),(25,'IA 3',1,0,'2026-02-13 13:48:55',1);
/*!40000 ALTER TABLE `exams` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `questions`
--

DROP TABLE IF EXISTS `questions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `questions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exam_id` int(11) NOT NULL,
  `question` text NOT NULL,
  `option_a` varchar(255) DEFAULT NULL,
  `option_b` varchar(255) DEFAULT NULL,
  `option_c` varchar(255) DEFAULT NULL,
  `option_d` varchar(255) DEFAULT NULL,
  `correct_option` char(1) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `exam_id` (`exam_id`),
  CONSTRAINT `questions_ibfk_1` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=69 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `questions`
--

LOCK TABLES `questions` WRITE;
/*!40000 ALTER TABLE `questions` DISABLE KEYS */;
INSERT INTO `questions` VALUES (61,24,'Which symbol is used for assignment?\r\n\r\n','==',':=','=','===','C'),(63,24,'Which function starts Java program execution?','start()','run()','main()','init()','C'),(64,24,'Which data type stores decimal values?','float','boolean','char','int','A'),(65,25,'Which keyword stops a loop?','break','end','stop','exit','A'),(68,25,'Which operator compares values?\r\n','=','==','+','++','B');
/*!40000 ALTER TABLE `questions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `results`
--

DROP TABLE IF EXISTS `results`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `results` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) DEFAULT NULL,
  `exam_id` int(11) DEFAULT NULL,
  `score` int(11) DEFAULT NULL,
  `taken_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `tab_switch_count` int(11) DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_exam_attempt` (`user_id`,`exam_id`),
  KEY `exam_id` (`exam_id`),
  CONSTRAINT `results_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  CONSTRAINT `results_ibfk_2` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=82 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `results`
--

LOCK TABLES `results` WRITE;
/*!40000 ALTER TABLE `results` DISABLE KEYS */;
INSERT INTO `results` VALUES (80,4,24,0,'2026-03-15 13:23:26',0),(81,4,25,2,'2026-03-15 14:33:10',0);
/*!40000 ALTER TABLE `results` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `student_answers`
--

DROP TABLE IF EXISTS `student_answers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `student_answers` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `student_id` int(11) DEFAULT NULL,
  `exam_id` int(11) DEFAULT NULL,
  `question_id` int(11) DEFAULT NULL,
  `answer` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `student_id` (`student_id`,`exam_id`,`question_id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `student_answers`
--

LOCK TABLES `student_answers` WRITE;
/*!40000 ALTER TABLE `student_answers` DISABLE KEYS */;
/*!40000 ALTER TABLE `student_answers` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','student') NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reset_token` varchar(255) DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL,
  `reset_otp` varchar(6) DEFAULT NULL,
  `otp_expiry` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Admin User','mailproject112@gmail.com','$2y$10$q8ittVtqZUrQrfeVFkOELOv/rZ2ITFvl7Exx7mbxGajKEPK38qgQi','admin','2026-02-08 06:06:34',NULL,NULL,NULL,NULL),(4,'Adi','admamadapur@gmail.com','$2y$10$aGaG/McZuAhpjRbkFtBNS.bZQQFBtGRZLXjUhl8gClFI8/vjZQoYy','student','2026-02-08 06:58:29',NULL,NULL,NULL,NULL),(5,'Adi M','admtemp67@gmail.com','$2y$10$isfrndOZ4HTCSM7wevYvRupJHIENzHbdNZT/rmCPdGiHjXPkQrA7S','student','2026-02-14 04:08:04',NULL,NULL,NULL,NULL),(6,'Renkigouda','studentquizportal01@gmail.com','$2y$10$p7CSHs0zOqKFdXRzH.Z./.P.631b4aGqykD1.AW2nlA9PZnWsQZhm','student','2026-02-14 17:37:55',NULL,NULL,'500808','2026-02-15 08:49:28');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `violation_report`
--

DROP TABLE IF EXISTS `violation_report`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `violation_report` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `violations` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id` (`user_id`),
  KEY `exam_id` (`exam_id`),
  CONSTRAINT `violation_report_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `violation_report_ibfk_2` FOREIGN KEY (`exam_id`) REFERENCES `exams` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `violation_report`
--

LOCK TABLES `violation_report` WRITE;
/*!40000 ALTER TABLE `violation_report` DISABLE KEYS */;
INSERT INTO `violation_report` VALUES (1,5,25,1,'2026-02-14 05:04:45'),(2,5,25,1,'2026-02-14 05:04:55'),(3,5,25,1,'2026-02-14 05:05:05'),(4,5,25,1,'2026-02-14 05:05:09'),(5,4,24,1,'2026-02-14 05:43:34'),(6,4,24,1,'2026-02-14 05:43:38'),(7,4,24,1,'2026-02-14 05:43:41'),(8,4,24,1,'2026-02-14 05:43:44'),(9,4,24,1,'2026-02-14 06:05:53'),(10,4,24,1,'2026-02-14 06:05:56'),(11,4,24,1,'2026-02-14 06:05:59'),(12,4,24,1,'2026-02-14 06:06:02'),(13,5,25,1,'2026-02-14 06:06:21'),(14,5,25,1,'2026-02-14 06:06:26'),(15,5,25,1,'2026-02-14 06:06:33'),(16,5,25,1,'2026-02-14 06:06:36'),(17,5,24,1,'2026-02-14 06:06:48'),(18,5,24,1,'2026-02-14 06:06:51'),(19,5,24,1,'2026-02-14 06:06:54');
/*!40000 ALTER TABLE `violation_report` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `violations`
--

DROP TABLE IF EXISTS `violations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `violations` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_id` int(11) NOT NULL,
  `exam_id` int(11) NOT NULL,
  `violation_count` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=61 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `violations`
--

LOCK TABLES `violations` WRITE;
/*!40000 ALTER TABLE `violations` DISABLE KEYS */;
INSERT INTO `violations` VALUES (44,4,24,0,'2026-03-15 13:22:42'),(45,4,24,0,'2026-03-15 13:22:55'),(46,4,25,0,'2026-03-15 13:24:46'),(47,4,25,0,'2026-03-15 13:27:25'),(48,4,25,0,'2026-03-15 13:31:07'),(49,4,25,0,'2026-03-15 13:31:17'),(50,4,25,0,'2026-03-15 13:33:49'),(51,4,25,0,'2026-03-15 13:38:27'),(52,4,25,0,'2026-03-15 13:40:19'),(53,4,25,0,'2026-03-15 13:54:16'),(54,4,25,0,'2026-03-15 13:56:24'),(55,4,25,0,'2026-03-15 13:58:07'),(56,4,25,0,'2026-03-15 13:58:19'),(57,4,25,0,'2026-03-15 14:07:39'),(58,4,25,0,'2026-03-15 14:08:57'),(59,4,25,0,'2026-03-15 14:09:07'),(60,4,25,0,'2026-03-15 14:33:11');
/*!40000 ALTER TABLE `violations` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-03-15 21:14:01
