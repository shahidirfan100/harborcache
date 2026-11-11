<?php
/**
 * CI fixture: allow application passwords over the disposable HTTP listener.
 * This file is copied to mu-plugins only inside the test container.
 */
add_filter( 'wp_is_application_passwords_available', '__return_true' );
add_filter( 'wp_is_application_passwords_available_for_user', '__return_true' );
