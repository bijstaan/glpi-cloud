# GLPI Cloud

Cloud resources as first-class inventory in GLPI 11, from any provider, without
a second asset system. Answers, per customer: what is running in their cloud
accounts, who it belongs to, and what it costs.

GLPI 11 has no cloud model — the only occurrences of the word in its source are
two icon names. This adds one, split across a core plugin and one plugin per
provider.

| Plugin | Key | Role |
|---|---|---|
| `glpi-cloud` | `glpicloud` | this one: the model, the sweep, the UI, the money |
| `glpi-cloud-azure` | `glpicloudazure` | Microsoft Azure |
| `glpi-cloud-ovh` | `glpicloudovh` | OVHcloud |
| `glpi-cloud-aws` | `glpicloudaws` | later |
| `glpi-cloud-gcp` | `glpicloudgcp` | later |

The seam is the API boundary. A provider plugin owns auth, endpoints,
pagination, throttling and its own vocabulary; this one owns everything
downstream of a normalised row and makes no HTTP call to a cloud endpoint. The
whole core is exercised in `tests/` against a fixture provider reading JSON off
disk.

## What it does

- **One canonical row per resource**, whatever the type — buckets, roles,
  security groups, functions, key vaults and VMs alike. Types with no mapping
  are stored with their exact provider type rather than dropped.
- **Opt-in projection onto native assets**, per type: VM → `Computer`, managed
  database → `DatabaseInstance`, cluster → `Cluster`. Off by default, since
  projecting a four-thousand-resource dev subscription into Computers rewrites
  the asset list the service desk depends on.
- **Cost through GLPI's own Management model.** A cloud account maps to a native
  `Contract` with a `Supplier`; each closed billing period becomes one
  `ContractCost` line attributed to the account's `Budget`, so core's budget
  screens count cloud spend with no reporting code here. Per-resource,
  per-month history stays in this plugin's tables.
- **Currency recorded as billed, never converted.** Unattributed spend
  (marketplace, support, reservations) is kept as its own row so an account
  total reconciles with the invoice.
- **Alerts** on a handful of glaring facts.

## Scope

- No control-plane writes. Every credential is read-only and the account form
  says so. Nothing is started, stopped, tagged, deleted or reconfigured.
- No agent and nothing in-guest; what runs inside a cloud VM is `glpiosquery`'s
  question.
- No posture scoring or CSPM.
- No forecasting — only what the provider billed.

## Sweep completion rule

A resource missing from a sweep is marked gone only if that sweep **completed**.
A sweep that threw, or ran out of its wall-clock budget, concludes nothing about
what is missing. Without this, one throttled request deletes a customer's
inventory and the next sweep rediscovers all of it with today's first-seen date.

## glpi-ai tools

Two read-only tools are registered when `glpiai` is present:

| Tool | Answers |
|---|---|
| `cloud_resources` | What is running in the customer's accounts: state, region, tags, last seen, and which GLPI asset it projects onto |
| `cloud_spend` | What it cost in a month, per account and currency, and whether the figure is still provisional |

Every other tool the assistant has reads the CMDB, so for a customer whose file
server is an Azure VM "no asset matches" is true about GLPI and false about
their estate. Each resource reports its projection, which tells the model
whether the other tools can reach the thing at all.

Spend is gated on the **account** right rather than the resource right: seeing
that a VM exists and seeing what the customer pays for it are different
permissions. Answers always state whether a month is provisional.

There are no write tools. The credentials are read-only by design, so a write
tool would be an argument for widening them.

## Writing a provider

One hook, one callable, returning plain data:

```php
$PLUGIN_HOOKS['glpicloud_providers']['glpicloudazure'] = [Provider::class, 'describe'];
```

```php
[
    'key'         => 'azure',
    'name'        => 'Microsoft Azure',
    'supplier'    => 'Microsoft',
    'credentials' => [
        ['key' => 'tenant_id', 'label' => 'Directory (tenant) ID', 'required' => true],
        ['key' => 'client_secret', 'label' => 'Client secret', 'secret' => true],
    ],
    'check'    => static fn(array $account): array => Auth::verify($account),
    'scopes'   => static fn(array $account): array => Subscriptions::list($account),
    'services' => [
        ['key' => 'compute', 'name' => 'Compute', 'types' => ['virtualmachine'],
         'collect' => static fn(array $ctx): iterable => Compute::walk($ctx)],
    ],
    'costs'    => static fn(array $ctx): iterable => Cost::walk($ctx),
]
```

Do not extend or implement a class of this plugin's. A class whose parent lives
in a deactivated or mid-upgrade plugin is a fatal at autoload time and takes out
every page touching it. Descriptors cross the boundary as arrays; parameter type
hints resolve lazily and are fine.

`tests/fixture-provider.php` is the worked example, including paging and
resumption: a provider hands back a cursor by calling
`$ctx['checkpoint']($token)`, and the core never looks inside it.

## Tests

```bash
tests/run.sh          # pure PHP, no GLPI, no network

docker compose -p glpi exec glpi \
  php /var/www/glpi/plugins/glpicloud/tests/sync.php      # writes to the DB
```

`registry.php` covers the provider contract: what is accepted, what is dropped,
and what is reported about it. `normalise.php` covers the checksum that decides
whether a six-hourly sweep of forty thousand resources writes anything at all.
`sync.php` covers the lifecycle, the money and the completion rule above.

## Install

```bash
# from the GLPI root — the directory must be named for the plugin key,
# which is not the repository name
git clone https://github.com/bijstaan/glpi-cloud.git plugins/glpicloud
php bin/console plugin:install -u glpi glpicloud
php bin/console plugin:activate glpicloud
```

## Licence

GPL-3.0-or-later, the same licence as GLPI. The plugin is loaded into GLPI's
process and extends its classes, so it is a derivative work. See
[LICENSE](LICENSE).
