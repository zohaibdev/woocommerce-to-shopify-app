import { Component, type ErrorInfo, type ReactNode } from "react";
import { Page, Card, Banner, BlockStack, Text, Button, InlineStack } from "@shopify/polaris";

interface Props {
  children: ReactNode;
}

interface State {
  error: Error | null;
}

/**
 * Catches a render error instead of letting it blank the app.
 *
 * React unmounts the entire tree when a render throws, and inside the Shopify
 * admin iframe that shows up as a white panel with nothing in it and no console
 * the merchant will ever open. Everything that reaches here is a bug worth
 * fixing, but the merchant should still be looking at an app — one they can
 * recover from with a reload — rather than at nothing at all.
 */
export default class ErrorBoundary extends Component<Props, State> {
  state: State = { error: null };

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    // Kept in the console rather than swallowed: this is how the bug behind a
    // "the app went blank" report actually gets found.
    console.error("Store Shift crashed while rendering", error, info.componentStack);
  }

  render() {
    const { error } = this.state;

    if (!error) return this.props.children;

    return (
      <Page title="Something went wrong">
        <BlockStack gap="400">
          <Banner tone="critical" title="This page could not be displayed">
            <BlockStack gap="200">
              <Text as="p">
                Nothing has happened to your data, and any migration that was running is still
                running — migrations run on the server, not in this page.
              </Text>
              <Text as="p" variant="bodySm" tone="subdued">
                {error.message}
              </Text>
            </BlockStack>
          </Banner>

          <Card>
            <InlineStack gap="200">
              <Button variant="primary" onClick={() => window.location.reload()}>
                Reload the app
              </Button>
              <Button onClick={() => this.setState({ error: null })}>Try again</Button>
            </InlineStack>
          </Card>
        </BlockStack>
      </Page>
    );
  }
}
