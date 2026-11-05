# NUIT

NUIT est un outil personnel de téléchargement YouTube pour macOS, avec une interface sombre en français. L’application tient dans un seul fichier : `index.php`.

## Site Internet avec service local

**Site : https://oublidee.github.io/nuit/**

L’interface web est une adaptation distincte de la version 1.0 locale validée. Elle nécessite un service sur le Mac ; elle ne fonctionne pas entièrement dans le cloud.

1. Installer les outils (voir ci-dessous).
2. Télécharger `service.php` dans un dossier dédié et ouvrir ce dossier dans Terminal.
3. Arrêter l’ancienne instance de NUIT avec `Ctrl+C` dans son Terminal, si elle utilise déjà le port 8787.
4. Lancer :

   ```sh
   php -S 127.0.0.1:8787 service.php
   ```

5. Ouvrir `http://127.0.0.1:8787/` dans Brave, choisir le dossier de destination puis cliquer sur **Autoriser le site pendant 8 heures**.
6. Copier le code privé et le coller dans **Connecter mon Mac** sur le site Internet. Brave peut demander l’autorisation d’accéder à cet ordinateur.
7. Garder Terminal ouvert pendant les transferts.

Le service reste limité à `127.0.0.1`. Aucun port du routeur n’a besoin d’être ouvert. Les fichiers et la session Brave restent sur le Mac. Le site public reçoit les titres, chemins de destination et informations de progression nécessaires à l’affichage, directement depuis le service local ; il n’a pas de serveur collectant ces données.

Le code de connexion est un secret : il autorise le site à utiliser le service local. Il est valable 8 heures et mémorisé uniquement pour l’onglet du navigateur. **Révoquer l’accès du site** sur la page locale ou **Déconnecter ce site** sur la page web invalide le code. Les transferts déjà lancés peuvent se terminer.

Le site ne peut agir que dans le dossier autorisé. Pour en changer depuis le site, utiliser **Choisir…**, puis sélectionner le nouveau dossier dans le dialogue natif du Finder. Un changement manuel du chemin sur le site ne suffit pas à donner accès à un autre dossier.

Le contrôle d’origine autorise `https://oublidee.github.io`. Les autres sites publiés sous cette même origine doivent également être considérés comme du code de confiance. En cas de doute sur un code de connexion, le révoquer depuis la page locale.

Pour régénérer `index.html` après une modification de `service.php` : `python3 build-site.py`. Le générateur ajoute une politique de sécurité avec les empreintes du script et des styles.

## Version 1.0 locale validée

Cette version est validée en utilisation locale. Elle ouvre YouTube dans un autre onglet, utilise facultativement la session Brave, et télécharge une vidéo MP4 ou un fichier audio MP3 dans le dossier choisi.

Fonctions : dossier mémorisé dans le navigateur, progression du transfert, historique, sélection multiple et choix entre effacement de l’historique seul ou mise des fichiers dans la Corbeille du Mac.

## Installation sur macOS

Avec Homebrew déjà installé, exécuter dans Terminal :

```sh
brew install php yt-dlp ffmpeg node
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

## Organisation du projet

- `index.php` : version 1.0 locale validée, conservée sans modification.
- `service.php` : service autonome et interface locale d’association pour la version web.
- `index.html` : interface statique hébergée par GitHub Pages.
- `build-site.py` : générateur du site statique, nécessaire seulement pour développer.

GitHub Pages n’exécute pas PHP. Le fichier `service.php` se lance uniquement sur le Mac de l’utilisateur. Les changements web ne doivent pas être interprétés comme une validation de la version web équivalente aux essais de la 1.0 locale.

Télécharger uniquement les contenus pour lesquels on dispose des droits nécessaires. Projet indépendant, non affilié à YouTube ou Google.
