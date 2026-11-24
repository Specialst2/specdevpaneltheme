# Spec UI

A panel theme for Spec Development on Pterodactyl Panel 1.x. Spec UI uses vanilla CSS, bundled Inter typography and the Spec Development mark.

## Local previews

Open the dashboard directly in Safari or Chrome, then select Survival SMP to try the console and its server tabs.

- [Dashboard preview](preview.html)
- [Server console preview](console-preview.html)
- [Files and server tools](pages-preview.html#files)
- [Account pages](pages-preview.html#account)
- [Sign-in and password recovery](auth-preview.html)
- [Administration preview](admin-preview.html)

The previews work offline with sample data, the shared stylesheet and bundled assets. Navigation opens the matching preview. Controls for changing panel data are read-only.

## Customize

Edit the custom properties at the top of [spec-ui.css](theme/spec-ui.css) to change the palette, page width, spacing and corner radius. The theme retains Pterodactyl's server status and alert colors, along with the terminal's ANSI log colors.

## Install, update, and uninstall

Use the panel's PHP CLI and run the command as the owner of the panel files. Pass the root of an existing Panel 1.x installation.

```sh
php spec-ui.php install /var/www/pterodactyl
php spec-ui.php update /var/www/pterodactyl
php spec-ui.php uninstall /var/www/pterodactyl
```

Install and update publish the CSS and bundled assets to a versioned directory under `public/themes/spec-ui/`. They add or replace a marked stylesheet link in `resources/views/layouts/scripts.blade.php` and clear Laravel's compiled view cache. Update uses the theme files in the current checkout and keeps earlier asset URLs available until uninstall.

Uninstall removes the marked link and installed theme files, preserving other contents of the Blade include and unrelated files. If the panel already has a manual Spec UI link and asset copy, remove the link and move that copy aside before running the installer.

The panel loads its existing frontend bundle alongside the stylesheet. The integration was checked against the shipped server, account, authentication and administration interfaces in [Pterodactyl Panel v1.15.2](https://github.com/pterodactyl/panel/releases/tag/v1.15.2).

Inter is distributed under the [SIL Open Font License](theme/assets/Inter-OFL.txt).
