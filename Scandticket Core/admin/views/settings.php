<div class="wrap">
    <h1><?php esc_html_e('ScandTicket Settings', 'scandticket'); ?></h1>
    <form method="post" action="options.php">
        <?php settings_fields('scandticket_settings'); ?>
        <?php do_settings_sections('scandticket_settings'); ?>
        <?php submit_button(); ?>
    </form>
</div>