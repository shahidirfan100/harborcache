<?php
/**
 * Query Montior output template
 *
 * @package HarborCache\Core
 */

defined( 'ABSPATH' ) || exit;

/**
 * @var \HarborCache\Core\QM_Output $this
 * @var array<mixed> $data
 */

// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo $this->before_non_tabular_output();
?>

    <section>
        <h3><?php esc_html_e( 'Status', 'harborcache-object-cache-with-redis' ); ?></h3>
        <p class="qm-ltr"><code><?php echo esc_html( $data['status'] ); ?></code></p>
    </section>

    <section>
        <h3><?php esc_html_e( 'Hit Ratio', 'harborcache-object-cache-with-redis' ); ?></h3>
        <p class="qm-ltr"><code><?php echo esc_html( $data['ratio'] ); ?>%</code></p>
    </section>

    <section>
        <h3><?php esc_html_e( 'Hits', 'harborcache-object-cache-with-redis' ); ?></h3>
        <p class="qm-ltr"><code><?php echo (int) $data['hits']; ?></code></p>
    </section>

    <section>
        <h3><?php esc_html_e( 'Misses', 'harborcache-object-cache-with-redis' ); ?></h3>
        <p class="qm-ltr"><code><?php echo (int) $data['misses']; ?></code></p>
    </section>

    <section>
        <h3><?php esc_html_e( 'Size', 'harborcache-object-cache-with-redis' ); ?></h3>
        <p class="qm-ltr"><code><?php echo esc_html( size_format( $data['bytes'], 2 ) ); ?></code></p>
    </section>

</div>

<?php if ( ! empty( $data['errors'] ) ) : ?>
    <div class="qm-boxed qm-boxed-wrap">

        <section>
            <h3><?php esc_html_e( 'Errors', 'harborcache-object-cache-with-redis' ); ?></h3>

            <table>
                <tbody>
                    <?php foreach ( $data['errors'] as $harborcache_err ) : ?>
                        <tr class="qm-warn">
                            <td class="qm-ltr qm-wrap">
                                <span class="dashicons dashicons-warning" aria-hidden="true"></span>
                                <?php echo esc_html( $harborcache_err ); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>

    </div>
<?php endif; ?>

<div class="qm-boxed qm-boxed-wrap">

    <?php if ( ! empty( $data['groups']['global'] ) ) : ?>
        <section>
            <h3><?php esc_html_e( 'Global Groups', 'harborcache-object-cache-with-redis' ); ?></h3>

            <ul class="qm-ltr">
                <?php foreach ( $data['groups']['global'] as $harborcache_group ) : ?>
                    <li>
                        <?php echo esc_html( $harborcache_group ); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ( ! empty( $data['groups']['non_persistent'] ) ) : ?>
        <section>
            <h3><?php esc_html_e( 'Non-persistent Groups', 'harborcache-object-cache-with-redis' ); ?></h3>

            <ul class="qm-ltr">
                <?php foreach ( $data['groups']['non_persistent'] as $harborcache_group ) : ?>
                    <li>
                        <?php echo esc_html( $harborcache_group ); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ( ! empty( $data['groups']['unflushable'] ) ) : ?>
        <section>
            <h3><?php esc_html_e( 'Unflushable Groups', 'harborcache-object-cache-with-redis' ); ?></h3>

            <ul class="qm-ltr">
                <?php foreach ( $data['groups']['unflushable'] as $harborcache_group ) : ?>
                    <li>
                        <?php echo esc_html( $harborcache_group ); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>

    <?php if ( ! empty( $data['meta'] ) ) : ?>
        <section>
            <h3><?php esc_html_e( 'Metadata', 'harborcache-object-cache-with-redis' ); ?></h3>

            <table>
                <tbody>
                    <?php foreach ( $data['meta'] as $harborcache_label => $harborcache_value ) : ?>
                        <tr>
                            <th scope="row"><?php echo esc_html( $harborcache_label ); ?></th>
                            <td class="qm-ltr qm-wrap"><?php echo esc_html( $harborcache_value ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </section>
    <?php endif; ?>

<?php if ( ! empty( $data['harborcache_commands'] ) ) : ?>
    <section>
        <h3><?php esc_html_e( 'Redis commands', 'harborcache-object-cache-with-redis' ); ?></h3>
        <p><?php esc_html_e( 'Up to 200 command names per request. Pipeline durations describe the whole batch. Keys and values are omitted.', 'harborcache-object-cache-with-redis' ); ?></p>
        <table><thead><tr><th><?php esc_html_e( 'Command', 'harborcache-object-cache-with-redis' ); ?></th><th><?php esc_html_e( 'Duration (ms)', 'harborcache-object-cache-with-redis' ); ?></th><th><?php esc_html_e( 'Batch', 'harborcache-object-cache-with-redis' ); ?></th></tr></thead><tbody>
        <?php foreach ( $data['harborcache_commands'] as $harborcache_command ) : ?>
            <tr><td><?php echo esc_html( $harborcache_command['command'] ); ?></td><td><?php echo esc_html( (string) $harborcache_command['milliseconds'] ); ?></td><td><?php echo esc_html( $harborcache_command['batch'] ? __( 'Yes', 'harborcache-object-cache-with-redis' ) : __( 'No', 'harborcache-object-cache-with-redis' ) ); ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
    </section>
<?php endif; ?>
<?php
// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
echo $this->after_non_tabular_output();
