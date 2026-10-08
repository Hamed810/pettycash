# Third-Party Notices

<!--
 GENERATED FILE — DO NOT EDIT BY HAND.

 Produced by `scripts/generate-third-party-notices.mjs --write` from `composer.lock`,
 `package-lock.json` and the built bundles' source maps in `js/`.

 An edit here is discarded by the next regeneration and fails `npm run check:notices`
 in the meantime. To change what is attributed, change a dependency.
 -->

Petty Cash is licensed under the GNU Affero General Public License v3.0 or later
(`<licence>agpl</licence>` in `appinfo/info.xml`). It bundles the third-party software listed
below, each under its own licence. Nothing here changes the terms of the application's own
licence; the bundled licences apply to the bundled code.

This notice covers the **76 packages that ship with the application**: 29 PHP
packages installed into `vendor/`, and 47 npm packages compiled into `js/`.

**Development dependencies are excluded**, because they are not shipped: `composer.lock`'s
`packages-dev` (PHPUnit, Psalm, the Nextcloud coding standard, Guzzle, the OCP stubs) is build
and test tooling, and the npm side is read from the bundles' source maps rather than from the
lock file. The npm lock file cannot make this distinction: it marks `vite`, `esbuild`, `rollup`,
`sass` and `typescript` as non-dev, so a notice built from it would attribute the build
toolchain as shipped software.

<!-- @dependencyFingerprint f809f79b4a0ea51e -->

## Licence summary

| Licence | Packages |
| --- | ---: |
| `MIT` | 43 |
| `LGPL-3.0-or-later` | 14 |
| `GPL-3.0-or-later` | 10 |
| `AGPL-3.0-or-later` | 3 |
| `Apache-2.0` | 2 |
| `(MPL-2.0 OR Apache-2.0)` | 1 |
| `BSD-2-Clause` | 1 |
| `BSD-3-Clause` | 1 |
| `ISC` | 1 |

Copyleft and file-level-copyleft licences are present (`LGPL-3.0-or-later`, `GPL-3.0-or-later`, `AGPL-3.0-or-later`). They are listed individually rather than folded into a permissive-licence summary, because their attribution and source-availability obligations differ from MIT/BSD/ISC/Apache.

## MIT

| Package | Version |
| --- | --- |
| `@floating-ui/core` | 1.8.0 |
| `@floating-ui/dom` | 1.8.0 |
| `@floating-ui/utils` | 0.2.12 |
| `@nextcloud/vue-select` | 4.1.0 |
| `@vue/reactivity` | 3.5.25, 3.5.43 |
| `@vue/runtime-core` | 3.5.25, 3.5.43 |
| `@vue/runtime-dom` | 3.5.25, 3.5.43 |
| `@vuepic/vue-datepicker` | 11.0.3 |
| `@vueuse/core` | 14.4.0 |
| `@vueuse/shared` | 14.4.0 |
| `axios` | 1.20.0 |
| `carbonphp/carbon-doctrine-types` | 3.2.1 |
| `composer/pcre` | 3.4.0 |
| `date-fns` | 4.4.0 |
| `debounce` | 3.0.0 |
| `escape-html` | 1.0.3 |
| `eventemitter3` | 5.0.4 |
| `floating-vue` | 5.4.0 |
| `focus-trap` | 8.2.2 |
| `is-retry-allowed` | 2.2.0 |
| `linkifyjs` | 4.3.3 |
| `maennchen/zipstream-php` | 3.2.2 |
| `markbaker/complex` | 3.0.2 |
| `markbaker/matrix` | 3.0.1 |
| `morilog/jalali` | v3.5.0 |
| `nesbot/carbon` | 3.14.2 |
| `p-queue` | 9.3.3 |
| `p-timeout` | 7.0.2 |
| `phpoffice/phpspreadsheet` | 5.10.0 |
| `pinia` | 3.0.4 |
| `psr/clock` | 1.0.0 |
| `psr/simple-cache` | 3.0.0 |
| `striptags` | 3.2.0 |
| `symfony/clock` | v8.1.0 |
| `symfony/polyfill-mbstring` | v1.38.2 |
| `symfony/translation` | v8.1.5 |
| `symfony/translation-contracts` | v3.7.1 |
| `tabbable` | 6.5.0 |
| `tributejs` | 5.1.3 |
| `ts-md5` | 2.0.1 |
| `vite-plugin-node-polyfills` | 0.23.0 |
| `vue-router` | 4.6.3, 5.3.1 |
| `webdav` | 5.11.0 |

## LGPL-3.0-or-later

| Package | Version |
| --- | --- |
| `tecnickcom/tc-lib-barcode` | 2.16.3 |
| `tecnickcom/tc-lib-color` | 3.0.6 |
| `tecnickcom/tc-lib-file` | 3.9.3 |
| `tecnickcom/tc-lib-pdf` | 8.76.1 |
| `tecnickcom/tc-lib-pdf-encrypt` | 2.11.3 |
| `tecnickcom/tc-lib-pdf-filter` | 2.11.3 |
| `tecnickcom/tc-lib-pdf-font` | 4.4.0 |
| `tecnickcom/tc-lib-pdf-graph` | 2.18.1 |
| `tecnickcom/tc-lib-pdf-image` | 3.14.3 |
| `tecnickcom/tc-lib-pdf-page` | 4.16.4 |
| `tecnickcom/tc-lib-pdf-parser` | 3.16.1 |
| `tecnickcom/tc-lib-pdf-sign` | 2.0.4 |
| `tecnickcom/tc-lib-unicode` | 3.0.8 |
| `tecnickcom/tc-lib-unicode-data` | 3.0.8 |

## GPL-3.0-or-later

| Package | Version |
| --- | --- |
| `@nextcloud/auth` | 2.6.0 |
| `@nextcloud/axios` | 2.6.0 |
| `@nextcloud/browser-storage` | 0.5.0 |
| `@nextcloud/capabilities` | 1.2.1 |
| `@nextcloud/event-bus` | 3.3.3 |
| `@nextcloud/initial-state` | 3.0.0 |
| `@nextcloud/l10n` | 3.4.1 |
| `@nextcloud/logger` | 3.0.3 |
| `@nextcloud/router` | 3.2.0 |
| `@nextcloud/sharing` | 0.4.0 |

## AGPL-3.0-or-later

| Package | Version |
| --- | --- |
| `@nextcloud/dialogs` | 7.5.0 |
| `@nextcloud/files` | 4.1.0 |
| `@nextcloud/vue` | 9.12.0 |

## Apache-2.0

| Package | Version |
| --- | --- |
| `@mdi/js` | 7.4.47 |
| `axios-retry` | 4.5.0 |

## (MPL-2.0 OR Apache-2.0)

| Package | Version |
| --- | --- |
| `dompurify` | 3.4.16 |

## BSD-2-Clause

| Package | Version |
| --- | --- |
| `beberlei/assert` | v3.3.4 |

## BSD-3-Clause

| Package | Version |
| --- | --- |
| `emoji-mart-vue-fast` | 15.0.5 |

## ISC

| Package | Version |
| --- | --- |
| `semver` | 7.7.4, 7.8.5 |

## Full licence texts

This notice names each package, its version and its licence identifier. It does **not**
reproduce the licence texts. Each is authoritative in the installed package itself:

- PHP: `vendor/<vendor>/<package>/LICENSE`, per the package's own distribution.
- npm: `node_modules/<package>/LICENSE`, per the package's own distribution.

Reproducing the texts here would create a second copy that can drift from the one the code is
actually distributed under, which is the failure a notice that names a location avoids.
