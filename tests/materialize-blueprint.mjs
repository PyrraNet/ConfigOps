import { readFile, writeFile } from 'node:fs/promises';

const [sourcePath, targetPath, phpVersion, wordpressVersion] = process.argv.slice(2);

if (!sourcePath || !targetPath || !phpVersion || !wordpressVersion) {
  throw new Error(
    'Usage: node tests/materialize-blueprint.mjs <source> <target> <php-version> <wordpress-version>',
  );
}

const blueprint = JSON.parse(await readFile(sourcePath, 'utf8'));

if (blueprint.version === 2) {
  throw new Error('This workaround is only for legacy Blueprint v1 declarations.');
}

blueprint.preferredVersions = {
  php: phpVersion,
  wp: wordpressVersion,
};

await writeFile(targetPath, `${JSON.stringify(blueprint, null, 2)}\n`);
