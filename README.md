# Forminator

Fork of WordPress plugin [Forminator by WPMU DEV](https://wordpress.org/plugins/forminator)

## Important

This repos has been created in order to create a modification on the codebase of the original plugin.
The modification is to allow to filter the notifications message body when forms are submitted.

Therefore make sure to keep this repo up to date with the original plugin but to ensure
the modified code is not overriden.

1. `$custom_form->notifications` usage in `library/modules/custom-forms/front/front-mail.php:168` see #075b18a0747850f942568da2328a6eb3f0826bc0
2. Add `$original_message param` in `forminator_email_message` filter in `library/abstracts/abstract-class-mail.php:394` see 94d193c79a5ffa9874a44269546b5e83b6b94d73
