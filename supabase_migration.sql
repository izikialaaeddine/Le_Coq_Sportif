-- Migration SQL pour Supabase (PostgreSQL)
-- Converti depuis MySQL pour Le Coq Sportif

-- Table Role (doit être créée en premier car référencée)
CREATE TABLE IF NOT EXISTS Role (
    idRole SERIAL PRIMARY KEY,
    Role VARCHAR(50) NOT NULL
);

-- Table Utilisateur
CREATE TABLE IF NOT EXISTS Utilisateur (
    idUtilisateur SERIAL PRIMARY KEY,
    idRole INTEGER REFERENCES Role(idRole),
    Identifiant VARCHAR(100) UNIQUE,
    MotDePasse VARCHAR(255),
    Nom VARCHAR(100),
    Prenom VARCHAR(100)
);

-- Table Couleur
CREATE TABLE IF NOT EXISTS Couleur (
    id SERIAL PRIMARY KEY,
    nom VARCHAR(100) NOT NULL UNIQUE
);

-- Table Famille
CREATE TABLE IF NOT EXISTS Famille (
    id SERIAL PRIMARY KEY,
    nom VARCHAR(100) NOT NULL UNIQUE
);

-- Table Echantillon
CREATE TABLE IF NOT EXISTS Echantillon (
    RefEchantillon VARCHAR(50) PRIMARY KEY,
    Famille VARCHAR(50),
    Couleur VARCHAR(50),
    Taille VARCHAR(50),
    Statut VARCHAR(50),
    Description TEXT,
    Qte INTEGER,
    DateCreation TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    idUtilisateur INTEGER REFERENCES Utilisateur(idUtilisateur) ON DELETE SET NULL
);

-- Table Demande
CREATE TABLE IF NOT EXISTS Demande (
    idDemande SERIAL PRIMARY KEY,
    idUtilisateur INTEGER REFERENCES Utilisateur(idUtilisateur),
    idValidateur INTEGER REFERENCES Utilisateur(idUtilisateur),
    TypeDemande VARCHAR(50),
    DateDemande TIMESTAMP,
    Statut VARCHAR(50),
    Qte INTEGER,
    Commentaire TEXT
);

-- Table DemandeEchantillon
CREATE TABLE IF NOT EXISTS DemandeEchantillon (
    id SERIAL PRIMARY KEY,
    idDemande INTEGER REFERENCES Demande(idDemande),
    refEchantillon VARCHAR(50) REFERENCES Echantillon(RefEchantillon),
    famille VARCHAR(50),
    couleur VARCHAR(50),
    qte INTEGER
);

-- Table Retour
CREATE TABLE IF NOT EXISTS Retour (
    idRetour SERIAL PRIMARY KEY,
    idUtilisateur INTEGER REFERENCES Utilisateur(idUtilisateur),
    idValidateur INTEGER REFERENCES Utilisateur(idUtilisateur),
    DateRetour TIMESTAMP,
    StatutRetour VARCHAR(50),
    Commentaire TEXT,
    Statut VARCHAR(50) NOT NULL DEFAULT 'En attente',
    qte INTEGER NOT NULL DEFAULT 1,
    idDemande INTEGER REFERENCES Demande(idDemande)
);

-- Table RetourEchantillon
CREATE TABLE IF NOT EXISTS RetourEchantillon (
    idRetourEchantillon SERIAL PRIMARY KEY,
    idRetour INTEGER REFERENCES Retour(idRetour),
    RefEchantillon VARCHAR(255) REFERENCES Echantillon(RefEchantillon),
    famille VARCHAR(255),
    couleur VARCHAR(255),
    qte INTEGER
);

-- Table Fabrication
CREATE TABLE IF NOT EXISTS Fabrication (
    idFabrication SERIAL PRIMARY KEY,
    RefEchantillon VARCHAR(50) REFERENCES Echantillon(RefEchantillon),
    idUtilisateur INTEGER REFERENCES Utilisateur(idUtilisateur),
    idValidateur INTEGER REFERENCES Utilisateur(idUtilisateur),
    DateCreation TIMESTAMP,
    StatutFabrication VARCHAR(50),
    Qte INTEGER,
    idLot VARCHAR(255)
);

-- Table Historique
CREATE TABLE IF NOT EXISTS Historique (
    idHistorique SERIAL PRIMARY KEY,
    idUtilisateur INTEGER REFERENCES Utilisateur(idUtilisateur),
    RefEchantillon VARCHAR(50),
    TypeAction VARCHAR(50),
    DateAction TIMESTAMP,
    Description TEXT
);

-- Table Contient
CREATE TABLE IF NOT EXISTS Contient (
    idDemande INTEGER REFERENCES Demande(idDemande),
    RefEchantillon VARCHAR(50) REFERENCES Echantillon(RefEchantillon),
    PRIMARY KEY (idDemande, RefEchantillon)
);

-- Table Emplacement
CREATE TABLE IF NOT EXISTS Emplacement (
    idRole INTEGER PRIMARY KEY REFERENCES Role(idRole),
    Role VARCHAR(50)
);

-- Table EstPour
CREATE TABLE IF NOT EXISTS EstPour (
    idRetour INTEGER REFERENCES Retour(idRetour),
    idDemande INTEGER REFERENCES Demande(idDemande),
    PRIMARY KEY (idRetour, idDemande)
);

-- Table Reception
CREATE TABLE IF NOT EXISTS Reception (
    idReception SERIAL PRIMARY KEY,
    RefEchantillon VARCHAR(50) REFERENCES Echantillon(RefEchantillon),
    idUtilisateur INTEGER REFERENCES Utilisateur(idUtilisateur),
    idValidateur INTEGER REFERENCES Utilisateur(idUtilisateur),
    DateReception DATE,
    Qte INTEGER
);

-- Table SeFaitPour
CREATE TABLE IF NOT EXISTS SeFaitPour (
    idFabrication INTEGER REFERENCES Fabrication(idFabrication),
    idDemande INTEGER REFERENCES Demande(idDemande),
    PRIMARY KEY (idFabrication, idDemande)
);

-- Table SeTrouve
CREATE TABLE IF NOT EXISTS SeTrouve (
    RefEchantillon VARCHAR(50) REFERENCES Echantillon(RefEchantillon),
    idRole INTEGER REFERENCES Emplacement(idRole),
    Qte INTEGER,
    PRIMARY KEY (RefEchantillon, idRole)
);

-- Insérer les rôles
INSERT INTO Role (Role) VALUES 
    ('Chef de Stock'),
    ('Chef de Groupe'),
    ('Réception'),
    ('Admin')
ON CONFLICT (idRole) DO NOTHING;

-- Insérer les utilisateurs (avec les hash de mots de passe existants)
INSERT INTO Utilisateur (idRole, Identifiant, MotDePasse, Nom, Prenom) VALUES
    (4, 'admin', '$2y$10$hzRxMnCeC4N.V55ZiNEYN.tCLVofnJIEjAg1z4Z4Zncd4G7Fek.FK', 'Admin', 'User'),
    (1, 'stock', '$2y$10$DL2CHmM7AESOlXwVLD3OiurECW4E.rK/I02RtFfyzH.v6NQDW.crW', 'Stock', 'Manager'),
    (2, 'groupe', '$2y$10$gANE3pnQFiEC1nHGICZwDOXsMF2xMQwPqyyJ2g1OkfgsqpFEswnWC', 'Groupe', 'Chef'),
    (3, 'reception', '$2y$10$S2J.GQhugmg6Hb2sAlQJmu3wVvA8KxhiLuKUMNskk0dpQYy2f.J/i', 'Réception', 'Agent')
ON CONFLICT (Identifiant) DO NOTHING;

-- Insérer les couleurs
INSERT INTO Couleur (id, nom) VALUES
    (20, 'Anthracite'),
    (4, 'Argent'),
    (11, 'Beige'),
    (5, 'Blanc'),
    (3, 'Bleu'),
    (18, 'Bleu marine'),
    (15, 'Bordeaux'),
    (14, 'Doré'),
    (19, 'Fuchsia'),
    (13, 'Gris'),
    (7, 'Jaune'),
    (17, 'Kaki'),
    (22, 'Le bleudasjkbdqeufbauisFD'),
    (10, 'Marron'),
    (2, 'Noir'),
    (8, 'Orange'),
    (9, 'Rose'),
    (1, 'Rouge'),
    (16, 'Turquoise'),
    (6, 'Vert'),
    (12, 'Violet'),
    (25, 'wdas')
ON CONFLICT (id) DO NOTHING;

-- Insérer les familles
INSERT INTO Famille (id, nom) VALUES
    (21, 'Acrylique'),
    (18, 'Caoutchouc'),
    (5, 'Céramique'),
    (10, 'Coton'),
    (2, 'Cuir'),
    (14, 'Denim'),
    (15, 'Élasthanne'),
    (9, 'Laine'),
    (11, 'Lin'),
    (6, 'Maille'),
    (4, 'Métal'),
    (16, 'Microfibre'),
    (17, 'Mousse'),
    (8, 'Nylon'),
    (3, 'Plastique'),
    (7, 'Polyester'),
    (20, 'Polyuréthane'),
    (19, 'PVC'),
    (28, 'sADX'),
    (24, 'Satin'),
    (12, 'Soie'),
    (1, 'Tissu'),
    (23, 'Toile'),
    (13, 'Velours'),
    (22, 'Viscose')
ON CONFLICT (id) DO NOTHING;

-- Insérer l'échantillon existant
-- Note: idUtilisateur=12 n'existe pas, on utilise NULL ou un utilisateur existant
INSERT INTO Echantillon (RefEchantillon, Famille, Couleur, Taille, Statut, Description, Qte, DateCreation, idUtilisateur) VALUES
    ('REF0192', 'Mousse', 'Argent', 'L', 'disponible', 'Edition limitée', 22, '2025-10-03 02:22:49', NULL)
ON CONFLICT (RefEchantillon) DO NOTHING;

-- Créer les séquences pour les AUTO_INCREMENT (si nécessaire)
-- PostgreSQL utilise SERIAL qui crée automatiquement les séquences, mais on peut les ajuster
SELECT setval('couleur_id_seq', (SELECT MAX(id) FROM Couleur));
SELECT setval('famille_id_seq', (SELECT MAX(id) FROM Famille));
SELECT setval('demande_iddemande_seq', (SELECT MAX(idDemande) FROM Demande));
SELECT setval('demandeechantillon_id_seq', (SELECT MAX(id) FROM DemandeEchantillon));
SELECT setval('fabrication_idfabrication_seq', (SELECT MAX(idFabrication) FROM Fabrication));
SELECT setval('historique_idhistorique_seq', (SELECT MAX(idHistorique) FROM Historique));
SELECT setval('retour_idretour_seq', (SELECT MAX(idRetour) FROM Retour));
SELECT setval('retourechantillon_idretourechantillon_seq', (SELECT MAX(idRetourEchantillon) FROM RetourEchantillon));
SELECT setval('utilisateur_idutilisateur_seq', (SELECT MAX(idUtilisateur) FROM Utilisateur));

-- Créer les index pour améliorer les performances
CREATE INDEX IF NOT EXISTS idx_demande_idutilisateur ON Demande(idUtilisateur);
CREATE INDEX IF NOT EXISTS idx_demande_idvalidateur ON Demande(idValidateur);
CREATE INDEX IF NOT EXISTS idx_demandeechantillon_iddemande ON DemandeEchantillon(idDemande);
CREATE INDEX IF NOT EXISTS idx_retour_idutilisateur ON Retour(idUtilisateur);
CREATE INDEX IF NOT EXISTS idx_retour_idvalidateur ON Retour(idValidateur);
CREATE INDEX IF NOT EXISTS idx_retourechantillon_idretour ON RetourEchantillon(idRetour);
CREATE INDEX IF NOT EXISTS idx_historique_idutilisateur ON Historique(idUtilisateur);
CREATE INDEX IF NOT EXISTS idx_historique_refechantillon ON Historique(RefEchantillon);
CREATE INDEX IF NOT EXISTS idx_fabrication_refechantillon ON Fabrication(RefEchantillon);
CREATE INDEX IF NOT EXISTS idx_fabrication_idutilisateur ON Fabrication(idUtilisateur);
CREATE INDEX IF NOT EXISTS idx_reception_refechantillon ON Reception(RefEchantillon);
CREATE INDEX IF NOT EXISTS idx_reception_idutilisateur ON Reception(idUtilisateur);

