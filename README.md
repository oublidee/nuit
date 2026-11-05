# NUIT 1.0

NUIT est un outil YouTube pour macOS avec une interface sombre en français. **L’application tient dans un seul fichier : `index.php`.**

Ce dépôt public sert à partager le fichier, conserver les versions et distribuer les mises à jour. NUIT s’exécute sur votre Mac.

## Fonctionnalités

- Ouvrir YouTube dans un autre onglet et coller le lien d’une vidéo.
- Télécharger une vidéo en MP4 ou son audio en MP3.
- Utiliser, au choix, la session YouTube déjà ouverte dans Brave.
- Choisir un dossier sur le Mac et le retrouver après rechargement.
- Afficher la progression et les derniers téléchargements.
- Sélectionner plusieurs entrées et effacer l’historique seul, ou mettre aussi les fichiers dans la Corbeille du Mac.

## Installation sur macOS

### 1. Installer Homebrew

Homebrew installe les outils nécessaires. Si la commande `brew --version` fonctionne déjà dans Terminal, passez à l’étape suivante.

Sinon, ouvrez Terminal et exécutez la commande officielle publiée sur [brew.sh](https://brew.sh/) :

```sh
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

Suivez les instructions affichées. Homebrew peut demander d’installer les outils de développement Apple et de saisir le mot de passe du Mac. Terminez les instructions **Next steps** affichées à la fin, notamment celles qui ajoutent Homebrew au chemin des commandes. Les commandes exactes dépendent de votre Mac. Ouvrez ensuite un nouveau Terminal et vérifiez :

```sh
brew --version
```

### 2. Installer PHP, ffmpeg et yt-dlp

```sh
brew install php ffmpeg yt-dlp node
```

- **PHP 8.1 ou ultérieur** exécute le fichier `index.php` sur le Mac.
- **yt-dlp** télécharge la vidéo ou l’audio.
- **ffmpeg** assemble la vidéo et l’audio et permet la conversion en MP3.
- **Node.js** fournit le moteur JavaScript utilisé par yt-dlp pour certaines vérifications YouTube.

Vérifiez l’installation :

```sh
php --version
ffmpeg -version
yt-dlp --version
node --version
```

NUIT détecte les outils dans les emplacements Homebrew habituels : `/opt/homebrew/bin` sur Apple Silicon et `/usr/local/bin` sur les Mac Intel.

### 3. Télécharger NUIT 1.0

Ouvrez [la version 1.0](https://github.com/oublidee/nuit/releases/tag/v1.0), puis téléchargez **Source code (zip)** dans la section **Assets** et décompressez-le.

Gardez `index.php` dans un dossier dédié. `README.md` contient ce guide et `.gitignore` sert uniquement au suivi Git : ces deux fichiers ne sont pas nécessaires pour lancer l’application.

### 4. Lancer NUIT

Dans Terminal, placez-vous dans le dossier contenant `index.php` : tapez `cd ` (avec un espace), glissez le dossier depuis le Finder dans Terminal, puis appuyez sur Entrée.

Lancez ensuite :

```sh
php -S 127.0.0.1:8787 index.php
```

Gardez cette fenêtre Terminal ouverte. Dans **Brave**, ouvrez :

**http://127.0.0.1:8787/**

Il faut passer par cette adresse ; un double-clic sur le fichier PHP ne lance pas l’application. Pour arrêter NUIT, faites **Ctrl+C** dans Terminal. Pour le relancer, réexécutez la même commande.

## Utilisation

1. Ouvrez YouTube dans Brave avec votre compte habituel.
2. Copiez le lien de la vidéo et collez-le dans NUIT.
3. Choisissez le format, la qualité et le dossier de destination.
4. Cliquez sur **Télécharger sur mon Mac** et gardez la page ouverte jusqu’à la fin.

macOS peut demander l’accès au trousseau pour lire la session Brave et l’autorisation de contrôler le Finder pour mettre les fichiers dans la Corbeille. NUIT ne demande aucun mot de passe dans son interface et n’exporte pas de fichier de cookies.

Pour une vidéo publique, vous pouvez désactiver **Utiliser ma session YouTube dans Brave**. Si vous avez plusieurs profils Brave, l’option de profil accepte `Default`, `Profile 1`, `Profile 2`, etc. Le chemin du profil est indiqué dans `brave://version`.

## Versions et mises à jour

La version **1.0** est le point de référence validé. Les versions publiées sont disponibles dans [Releases](https://github.com/oublidee/nuit/releases). Les modifications du code sont visibles dans [l’historique des commits](https://github.com/oublidee/nuit/commits/main/).

### Mettre à jour l’application

Arrêtez NUIT avec **Ctrl+C**, conservez une copie de votre ancien `index.php`, puis remplacez-le par celui de la nouvelle version téléchargée dans **Releases**. Relancez la commande PHP et rechargez la page dans Brave.

Le dossier mémorisé et l’historique sont conservés dans le navigateur : utilisez la même adresse et le même profil Brave pour les retrouver.

Les mises à jour de l’application ne sont pas automatiques. GitHub permet de publier, télécharger et comparer les versions. Pour être averti des nouvelles versions, utilisez **Watch → Custom → Releases** sur le dépôt.

### Mettre à jour les outils

```sh
brew update
brew upgrade php ffmpeg yt-dlp node
```

Si YouTube change son fonctionnement et qu’un téléchargement échoue, mettre `yt-dlp` à jour peut résoudre le problème.

### Récupérer le code avec Git (facultatif)

Pour suivre le projet avec Git :

```sh
git clone https://github.com/oublidee/nuit.git
cd nuit
php -S 127.0.0.1:8787 index.php
```

Pour récupérer les modifications de la branche principale, arrêtez NUIT, puis exécutez depuis ce dossier :

```sh
git pull --ff-only
```

La branche `main` peut contenir des changements plus récents qu’une version publiée. Pour une version précise, privilégiez le téléchargement dans **Releases**. Si vous avez modifié le code localement, sauvegardez vos changements avant de mettre à jour.

## Dépannage et sécurité

- **« Address already in use »** : une autre instance utilise le port 8787. Arrêtez-la dans son Terminal avec **Ctrl+C** avant de relancer NUIT.
- **Outil introuvable** : vérifiez son installation, les instructions Homebrew et les emplacements indiqués plus haut.
- **Session Brave inaccessible** : ouvrez Brave et examinez les demandes d’autorisation macOS ; pour une vidéo publique, essayez sans la session Brave.
- Le serveur doit rester sur **127.0.0.1**. Utilisez exactement la commande avec `index.php` comme routeur et ne l’exposez pas sur Internet.
- Les préférences et l’historique sont stockés dans le navigateur. Les téléchargements restent dans le dossier choisi sur le Mac.
- Gardez les outils à jour. Les contrôles intégrés ne garantissent pas l’absence de toute vulnérabilité et ne protègent pas un Mac déjà compromis.

Téléchargez uniquement les contenus pour lesquels vous disposez des droits nécessaires. Projet indépendant, non affilié à YouTube ou Google.
