-- Tenant admin subscription overview module (plan-linked feature)
INSERT INTO features (feature_name, description)
SELECT
    'subscription_center',
    'View plan, term window, and subscription history in tenant admin'
WHERE NOT EXISTS (
    SELECT 1 FROM features WHERE feature_name = 'subscription_center'
);

INSERT IGNORE INTO plan_features (plan_id, feature_id, is_included)
SELECT sp.plan_id, f.feature_id, 1
FROM subscription_plans sp
CROSS JOIN features f
WHERE f.feature_name = 'subscription_center';
