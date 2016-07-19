create database unyleya;
use unyleya;

create table modelo (
  id_modelo int PRIMARY KEY AUTO_INCREMENT,
  nome varchar(100) NOT NULL,
  ano_modelo varchar(4) NOT NULL,
  aro_roda int(2) NOT NULL
);
create table acessorio (
  id_acessorio int primary key AUTO_INCREMENT,
  id_modelo int NOT NULL REFERENCES modelo (id_modelo) ON DELETE CASCADE,
  nome varchar(100) NOT NULL,
  opcional boolean DEFAULT FALSE
);