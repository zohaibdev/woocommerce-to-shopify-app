import { useEffect, useState } from "react";
import {
  Page,
  Card,
  ResourceList,
  ResourceItem,
  Text,
  Badge,
  EmptyState,
  SkeletonBodyText,
} from "@shopify/polaris";
import { useNavigate } from "react-router-dom";
import { api, type Migration } from "../api/client";

const statusTone: Record<Migration["status"], "success" | "critical" | "attention" | "info"> = {
  completed: "success",
  completed_with_errors: "attention",
  failed: "critical",
  cancelled: "critical",
  processing: "info",
  pending: "info",
  paused: "attention",
};

export default function MigrationHistory() {
  const navigate = useNavigate();
  const [migrations, setMigrations] = useState<Migration[]>([]);
  const [loading, setLoading] = useState(true);

  useEffect(() => {
    api
      .listMigrations()
      .then(({ data }) => setMigrations(data))
      .finally(() => setLoading(false));
  }, []);

  return (
    <Page
      title="Migrations"
      primaryAction={{ content: "New migration", onAction: () => navigate("/migrations/new") }}
    >
      <Card padding="0">
        {loading ? (
          <div style={{ padding: 16 }}>
            <SkeletonBodyText lines={5} />
          </div>
        ) : migrations.length === 0 ? (
          <EmptyState
            heading="No migrations yet"
            action={{ content: "Start new migration", onAction: () => navigate("/migrations/new") }}
            image="https://cdn.shopify.com/s/files/1/0262/4071/2726/files/emptystate-files.png"
          >
            <p>Run your first WooCommerce → Shopify migration.</p>
          </EmptyState>
        ) : (
          <ResourceList
            items={migrations}
            renderItem={(m) => (
              <ResourceItem
                id={String(m.id)}
                onClick={() => navigate(`/migrations/${m.id}`)}
                accessibilityLabel={`Migration ${m.id}`}
              >
                <Text as="h3" fontWeight="bold">
                  {m.name ? m.name : `#${m.id}`} — {m.selected_entities.join(", ")}
                </Text>
                <Badge tone={statusTone[m.status]}>{m.status}</Badge>
                <Text as="p" tone="subdued">
                  {new Date(m.created_at).toLocaleString()}
                </Text>
              </ResourceItem>
            )}
          />
        )}
      </Card>
    </Page>
  );
}
