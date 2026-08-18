import { Banner, BlockStack, List, Text } from "@shopify/polaris";

/**
 * The single most common reason a migration fails or crawls on a store that is
 * otherwise perfectly healthy.
 *
 * A migration is not a handful of API calls — it is tens of thousands of them,
 * from one IP address, over hours. To a WordPress security plugin or a hosting
 * firewall that is indistinguishable from an attack, so they start throttling,
 * challenging or outright blocking the requests. The merchant then sees records
 * failing with timeouts and reasonably concludes the app is broken.
 *
 * Said BEFORE the run rather than in the failure list afterwards, because by
 * then they have already lost hours. Deliberately worded as "allow us through"
 * rather than "turn off your security": telling a merchant to disable protection
 * on a live store is advice no one should give, and a temporary allowlist for
 * one integration is the correct remedy anyway.
 */
export default function SecurityPluginWarning({
  tone = "info",
}: {
  tone?: "info" | "warning";
}) {
  return (
    <Banner tone={tone} title="Check your WordPress security plugin first">
      <BlockStack gap="200">
        <Text as="p">
          Migrating reads your whole store through the WooCommerce API, which means thousands of
          requests from one place over several hours. Security plugins, firewalls and hosting
          protection often treat that as suspicious traffic and start slowing it down or blocking
          it — which shows up here as a slow migration or records that fail with timeouts.
        </Text>
        <Text as="p" fontWeight="semibold">
          Common things that block a migration:
        </Text>
        <List type="bullet">
          <List.Item>
            Security plugins such as Wordfence, Sucuri, iThemes/SolidWP Security or All In One WP
            Security
          </List.Item>
          <List.Item>Rate limiting or “login/API protection” built into your hosting</List.Item>
          <List.Item>A web application firewall (WAF) such as Cloudflare or your host’s own</List.Item>
          <List.Item>Bot protection that challenges automated requests</List.Item>
        </List>
        <Text as="p">
          If your migration is slow or records start failing, ask your host or check your security
          plugin for blocked requests to <code>/wp-json/</code>, and allowlist them for the duration
          of the migration. Turn the protection back to normal once it has finished — there is no
          need to leave your store unprotected.
        </Text>
      </BlockStack>
    </Banner>
  );
}
