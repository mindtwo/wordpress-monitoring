#!/usr/bin/env bash
#
# Builds the installable plugin ZIP for WordPress sites without Composer:
# the committed plugin (git archive, honouring export-ignore) plus a bundled
# production vendor/ resolved for the lowest supported PHP version.
#
#   bin/build-zip.sh <version> [output-dir]
#
# Only committed files of HEAD are packaged. The repository itself is never
# modified; require-dev is stripped in the temporary build copy only.

set -euo pipefail

version="${1:?usage: bin/build-zip.sh <version> [output-dir]}"
version="${version#v}"
root="$(cd "$(dirname "$0")/.." && pwd)"
out_dir="${2:-$root/dist}"

# Resolve before any cd: a relative path would otherwise land in the temporary
# build directory and be deleted with it.
mkdir -p "$out_dir"
out_dir="$(cd "$out_dir" && pwd)"

if ! [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Version must be MAJOR.MINOR.PATCH, got '$version'." >&2
    exit 1
fi

header() {
    git -C "$root" show HEAD:wordpress-monitoring.php | sed -n "s/^ \* $1: *//p" | tr -d '[:space:]'
}

header_version="$(header 'Version')"

# The plugin header is the single source for the PHP minimum: WordPress reads
# it before installing, the updater reads it from the tag, and the bundle is
# resolved against it here.
lowest_php="$(header 'Requires PHP')"

if ! [[ "$lowest_php" =~ ^[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
    echo "Plugin header has no valid 'Requires PHP', got '$lowest_php'." >&2
    exit 1
fi

if [ "$header_version" != "$version" ]; then
    # A mismatch would make WordPress offer the same update forever.
    echo "Plugin header says Version: $header_version, but the release is $version." >&2
    exit 1
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

git -C "$root" archive --prefix=wordpress-monitoring/ HEAD | tar -x -C "$work"

cd "$work/wordpress-monitoring"

# Dev tooling (Pint, Pest, PHPStan) needs PHP >= 8.1 and is not shipped; drop it
# so the bundle resolves against the lowest supported PHP version.
php -r '
    $file = "composer.json";
    $json = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    unset($json["require-dev"], $json["autoload-dev"], $json["scripts"]);
    $json["config"]["platform"]["php"] = $argv[1];
    file_put_contents($file, json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
' "$lowest_php"

COMPOSER_ROOT_VERSION="$version" composer install \
    --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-progress

php -r '
    require "vendor/autoload.php";
    foreach (["Mindtwo\\Monitoring\\WordPress\\Plugin", "Mindtwo\\Monitoring\\Monitor"] as $class) {
        if (! class_exists($class)) {
            fwrite(STDERR, "Bundled autoloader cannot load $class.".PHP_EOL);
            exit(1);
        }
    }
    $reported = Composer\InstalledVersions::getPrettyVersion("mindtwo/wordpress-monitoring");
    if ($reported !== $argv[1]) {
        fwrite(STDERR, "Bundle reports version $reported instead of $argv[1].".PHP_EOL);
        exit(1);
    }
' "$version"

zip_path="$out_dir/wordpress-monitoring-$version.zip"
rm -f "$zip_path"

cd "$work"
zip -qr "$zip_path" wordpress-monitoring

echo "$zip_path"
