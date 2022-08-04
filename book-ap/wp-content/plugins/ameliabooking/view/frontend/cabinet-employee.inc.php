<?php
/**
 * @copyright © TMS-Plugins. All rights reserved.
 * @licence   See LICENCE.md for license details.
 */

?>

<?php
if (!empty($_GET['page']) && !empty($_GET['code'])) {
    $code = strip_tags($_GET['code']);
    echo "
<script>
var url = new URL(window.location.href);
url.searchParams.set('code', '" . $code . "');
window.history.replaceState(null, null, url);
</script>
    ";
}
?>

<script>
<?php
    $timeZones = json_encode(\DateTimeZone::listIdentifiers(\DateTimeZone::ALL));
    echo "var wpAmeliaTimeZones = $timeZones;";
?>
  var bookingEntitiesIds = (typeof bookingEntitiesIds === 'undefined') ? [] : bookingEntitiesIds;
  bookingEntitiesIds.push(
    {
      'hasApiCall': 1,
      'trigger': '<?php echo $atts['trigger']; ?>',
      'counter': '<?php echo $atts['counter']; ?>',
      'cabinetType': 'employee',
      'appointments': '<?php echo $atts['appointments']; ?>',
      'events': '<?php echo $atts['events']; ?>',
      'profile': '<?php echo $atts['profile-hidden'] == '1' ? 1 : ''; ?>'
    }
  );
  var lazyBookingEntitiesIds = (typeof lazyBookingEntitiesIds === 'undefined') ? [] : lazyBookingEntitiesIds;
  if (bookingEntitiesIds[bookingEntitiesIds.length - 1].trigger !== '') {
    lazyBookingEntitiesIds.push(bookingEntitiesIds.pop());
  }
</script>

<div id="amelia-app-booking<?php echo $atts['counter']; ?>" class="amelia-cabinet amelia-frontend amelia-app-booking<?php echo $atts['trigger'] ? ' amelia-skip-load amelia-skip-load-' . $atts['counter'] : ''; ?>">
  <cabinet :cabinet-type="'provider'"></cabinet>
</div>
