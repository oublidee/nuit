# NUIT 1.0

NUIT est un outil personnel de téléchargement YouTube pour macOS, avec une interface sombre en français. L’application tient dans un seul fichier : `index.php`.

## Version 1.0

Cette version est validée en utilisation locale. Elle ouvre YouTube dans un autre onglet, utilise facultativement la session Brave, et télécharge une vidéo MP4 ou un fichier audio MP3 dans le dossier choisi.

Fonctions : dossier mémorisé dans le navigateur, progression du transfert, historique, sélection multiple et choix entre effacement de l’historique seul ou mise des fichiers dans la Corbeille du Mac.

## Installation sur macOS

Avec Homebrew déjà installé, exécuter dans Terminal :

```sh
brew install php yt-dlp ffmpeg
```

Télécharger `index.php` dans un dossier dédié, ouvrir ce dossier dans Terminal, puis lancer :

```sh
php -S 127.0.0.1:8787 index.php
```

Garder Terminal ouvert et ouvrir `http://127.0.0.1:8787` dans Brave. PHP 8.1 ou ultérieur est nécessaire. Un moteur JavaScript compatible avec yt-dlp peut également être nécessaire ; NUIT détecte Node.js lorsqu’il est installé dans un emplacement standard.

macOS peut demander l’accès au trousseau pour la session Brave, et l’autorisation de contrôler le Finder pour mettre des fichiers dans la Corbeille.

## Sécurité et confidentialité

- Le serveur reste limité à `127.0.0.1` ; il ne faut pas l’exposer sur un réseau public.
- Utiliser exactement la commande ci-dessus, avec `index.php` comme routeur.
- Aucun mot de passe n’est demandé dans l’interface et aucun fichier de cookies n’est exporté par NUIT.
- Les préférences et l’historique sont enregistrés dans le navigateur.
- Les opérations sont protégées par un jeton de session et des contrôles d’origine.
- Maintenir PHP, yt-dlp, ffmpeg et le moteur JavaScript à jour. Ces protections ne constituent pas une garantie contre toutes les vulnérabilités.

## Publication sur Internet

Ce dépôt partage le code. La version 1.0 fonctionne sur le Mac de l’utilisateur, pas sur GitHub Pages, qui n’exécute pas PHP. Une version web avec service local nécessite une adaptation distincte.

Télécharger uniquement les contenus pour lesquels on dispose des droits nécessaires. Projet indépendant, non affilié à YouTube ou Google.
