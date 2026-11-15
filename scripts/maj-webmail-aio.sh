#!/bin/bash
# Mise à jour de l'app Webmail Exchange dans Nextcloud AIO, en une commande.
#
#   sudo ./maj-webmail-aio.sh              dernière version publiée
#   sudo ./maj-webmail-aio.sh 1.142.0      une version précise
#   sudo ./maj-webmail-aio.sh --retour     remet la version d'avant (dernière sauvegarde)
#
# Options :
#   -c, --conteneur NOM   conteneur Nextcloud (défaut : nextcloud-aio-nextcloud)
#   -y, --oui             sans demander de confirmation
#   -f, --forcer          réinstalle même si la version est déjà en place
#   -h, --aide            cette aide
#
# Étapes : téléchargement depuis les releases GitHub, contrôle de l'empreinte SHA-256,
# sauvegarde de la version en place (dans /var/backups/webmail, les 5 dernières sont gardées),
# remplacement du dossier custom_apps/webmail, puis « occ upgrade ». En cas d'échec
# de l'installation, la version d'avant est remise d'elle-même.
set -euo pipefail

REPO="infoaicinformatique/webmail"
CONTENEUR="${CONTENEUR:-nextcloud-aio-nextcloud}"
APPS="/var/www/html/custom_apps"
SAUVEGARDES="${SAUVEGARDES:-/var/backups/webmail}"
GARDER=5
VERSION=""
OUI=0
FORCER=0
RETOUR=0

aide() { sed -n '2,18p' "$0" | sed 's/^# \{0,1\}//'; }
info() { printf '\033[1;34m▶\033[0m %s\n' "$*"; }
ok() { printf '\033[1;32m✔\033[0m %s\n' "$*"; }
erreur() { printf '\033[1;31m✘\033[0m %s\n' "$*" >&2; exit 1; }

while [ $# -gt 0 ]; do
	case "$1" in
		-c|--conteneur) CONTENEUR="${2:?nom du conteneur manquant}"; shift 2 ;;
		-y|--oui) OUI=1; shift ;;
		-f|--forcer) FORCER=1; shift ;;
		-r|--retour) RETOUR=1; shift ;;
		-h|--aide|--help) aide; exit 0 ;;
		-*) erreur "option inconnue : $1 (voir --aide)" ;;
		*) VERSION="${1#v}"; shift ;;
	esac
done

[ "$(id -u)" -eq 0 ] || erreur "à lancer en root (sudo $0 …)"
command -v docker >/dev/null || erreur "docker introuvable"
docker info >/dev/null 2>&1 || erreur "Docker ne répond pas (service arrêté ?)"
command -v curl >/dev/null || erreur "curl introuvable (apt install curl)"
command -v sha256sum >/dev/null || erreur "sha256sum introuvable"
[ "$(docker inspect -f '{{.State.Running}}' "$CONTENEUR" 2>/dev/null)" = true ] \
	|| erreur "le conteneur $CONTENEUR ne tourne pas (voir docker ps, ou l'option -c)"

dans() { docker exec "$CONTENEUR" "$@"; }
occ() { docker exec --user www-data "$CONTENEUR" php occ "$@"; }
version_en_place() {
	dans sh -c "sed -n 's:.*<version>\(.*\)</version>.*:\1:p' $APPS/webmail/appinfo/info.xml 2>/dev/null" | head -n1
}
confirmer() {
	[ "$OUI" -eq 1 ] && return 0
	read -r -p "$1 [o/N] " r
	case "$r" in o|O|oui|Oui|OUI|y|Y) return 0 ;; *) echo "Abandon."; exit 0 ;; esac
}

# met l'archive $1 (sur l'hôte) à la place de custom_apps/webmail, sans laisser de dossier à moitié copié
installer() {
	dans sh -c "rm -rf /tmp/webmail-maj && mkdir -p /tmp/webmail-maj" || return 1
	docker cp "$1" "$CONTENEUR":/tmp/webmail-maj/archive.tar.gz || return 1
	dans sh -c "cd /tmp/webmail-maj && tar -xzf archive.tar.gz && test -f webmail/appinfo/info.xml" || return 1
	dans sh -c "rm -rf $APPS/webmail.ancien && if [ -d $APPS/webmail ]; then mv $APPS/webmail $APPS/webmail.ancien; fi \
		&& mv /tmp/webmail-maj/webmail $APPS/webmail && chown -R www-data:www-data $APPS/webmail \
		&& rm -rf $APPS/webmail.ancien /tmp/webmail-maj"
}

mkdir -p "$SAUVEGARDES"
ACTUELLE="$(version_en_place || true)"

if [ "$RETOUR" -eq 1 ]; then
	DERNIERE="$(ls -1t "$SAUVEGARDES"/webmail-*.tar.gz 2>/dev/null | head -n1 || true)"
	[ -n "$DERNIERE" ] || erreur "aucune sauvegarde dans $SAUVEGARDES"
	info "Version en place : ${ACTUELLE:-aucune}"
	confirmer "Remettre $(basename "$DERNIERE") ?"
	installer "$DERNIERE"
	occ upgrade || true
	ok "Remis : $(version_en_place)"
	exit 0
fi

if [ -z "$VERSION" ]; then
	info "Recherche de la dernière version publiée…"
	VERSION="$(curl -fsSL "https://api.github.com/repos/$REPO/releases/latest" \
		| sed -n 's/.*"tag_name": *"v\{0,1\}\([^"]*\)".*/\1/p' | head -n1)"
	[ -n "$VERSION" ] || erreur "impossible de lire la dernière version sur GitHub (réseau ?) : indiquez-la, par exemple $0 1.142.0"
fi
[[ "$VERSION" =~ ^[0-9]+(\.[0-9]+)*$ ]] || erreur "version invalide : $VERSION"

info "Version en place : ${ACTUELLE:-aucune}   →   version demandée : $VERSION"
if [ "$ACTUELLE" = "$VERSION" ] && [ "$FORCER" -eq 0 ]; then
	ok "Déjà à jour (--forcer pour réinstaller)."
	exit 0
fi
if [ -n "$ACTUELLE" ] && [ "$(printf '%s\n%s\n' "$ACTUELLE" "$VERSION" | sort -V | head -n1)" = "$VERSION" ] && [ "$ACTUELLE" != "$VERSION" ]; then
	info "Attention : $VERSION est plus ancienne que la version en place ($ACTUELLE)."
fi
confirmer "Installer la version $VERSION ?"

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
BASE="https://github.com/$REPO/releases/download/v$VERSION"
ARCHIVE="webmail-$VERSION.tar.gz"

info "Téléchargement de $ARCHIVE…"
curl -fsSL -o "$TMP/$ARCHIVE" "$BASE/$ARCHIVE" || erreur "téléchargement impossible : $BASE/$ARCHIVE (version publiée ?)"
curl -fsSL -o "$TMP/$ARCHIVE.sha256" "$BASE/$ARCHIVE.sha256" || erreur "empreinte introuvable : $BASE/$ARCHIVE.sha256"
(cd "$TMP" && sha256sum -c --quiet "$ARCHIVE.sha256") || erreur "empreinte SHA-256 incorrecte : archive refusée"
ok "Empreinte vérifiée : $(cut -d' ' -f1 "$TMP/$ARCHIVE.sha256")"
DANS_ARCHIVE="$(tar -xzOf "$TMP/$ARCHIVE" webmail/appinfo/info.xml | sed -n 's:.*<version>\(.*\)</version>.*:\1:p' | head -n1)"
[ "$DANS_ARCHIVE" = "$VERSION" ] || erreur "l'archive annonce la version '$DANS_ARCHIVE' au lieu de $VERSION"

SAUVEGARDE=""
if [ -n "$ACTUELLE" ]; then
	SAUVEGARDE="$SAUVEGARDES/webmail-$ACTUELLE-$(date +%Y%m%d-%H%M%S).tar.gz"
	info "Sauvegarde de la version $ACTUELLE dans $SAUVEGARDE"
	dans tar -czf - -C "$APPS" webmail > "$SAUVEGARDE"
	ls -1t "$SAUVEGARDES"/webmail-*.tar.gz | tail -n +$((GARDER + 1)) | xargs -r rm -f
fi

info "Installation…"
if ! installer "$TMP/$ARCHIVE"; then
	[ -n "$SAUVEGARDE" ] && { info "Échec : remise de la version $ACTUELLE"; installer "$SAUVEGARDE"; }
	erreur "installation échouée"
fi

info "occ upgrade…"
if ! occ upgrade; then
	echo
	echo "« occ upgrade » a échoué. Pour revenir à la version d'avant :  sudo $0 --retour"
	exit 1
fi
occ app:enable webmail >/dev/null 2>&1 || true

ok "Webmail Exchange $(version_en_place) installé."
