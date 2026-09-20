=== Jotform Bridge ===
Contributors: suborgerry
Tags: jotform, forms, contact form, headless, custom form
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Use Jotform as a headless form backend: your own HTML on the site, submissions and data on Jotform.

== Description ==

Use custom PHP templates or automatically generated forms with Jotform as the
backend. Local integrations bind your markup to Jotform; fields use semantic
names, and submissions are validated server-side. Includes spam protection and
optional success redirects and local conditional field rules. No production dependencies or build step.
Updates are delivered from GitHub Releases through the ordinary Updates screen.

Documentation: https://github.com/suborgerry/JotForm-Bridge/blob/main/docs/README.md

== Installation ==

1. Upload the plugin ZIP through Plugins → Add New → Upload Plugin and activate.
2. Add `define('JOTFORM_API_KEY', 'your-api-key');` to wp-config.php.
3. Open Jotform Bridge → Settings, select the API region and check the connection.
4. Add an integration, enter the Jotform form ID, press Connect form and save.
   Auto rendering is the default; use Sync Schema to refresh an existing definition.
5. Render with [jotform_form id="contact"] or echo jotform_bridge_render('contact');
   using your integration slug.

Setup guide: https://github.com/suborgerry/JotForm-Bridge/blob/main/docs/getting-started.md
Custom templates: https://github.com/suborgerry/JotForm-Bridge/blob/main/docs/custom-templates.md

== Changelog ==

= 2.0.13 =
* Check again on the Updates screen now really asks GitHub again. It used to answer from a cache of up to twelve hours, so a release published after the last check was not offered until the cache expired.

= 2.0.11 =
* The plugin version is written down once, in the plugin header; the constant, the asset URLs and the upgrade routine read it from there.
* A release is now a version bump merged into main: the workflow creates the tag and the GitHub Release itself.

= 2.0.0 =
* Updates arrive from GitHub Releases through the ordinary Updates screen; no more manual ZIP uploads.
* Local conditional visibility and requirements per integration, evaluated in the browser and on the server.

= 1.1.0 =
* Connect form loads missing schemas; automatic rendering is now the default.
* Added optional email domain restrictions and clearer connection diagnostics.
* Improved debug logging and removed redundant notices.

Full release history: https://github.com/suborgerry/JotForm-Bridge/blob/main/docs/changelog.md
