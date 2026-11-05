-- Lancé une seule fois, à la création du volume MySQL.
-- L'utilisateur « playora » a déjà tous les droits sur la base « playora » ;
-- on lui donne aussi la base de test « playora_test » (créée par doctrine:database:create --env=test).
CREATE DATABASE IF NOT EXISTS `playora_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON `playora_test`.* TO 'playora'@'%';
FLUSH PRIVILEGES;
