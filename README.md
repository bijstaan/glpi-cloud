# GLPI Cloud

Cloud resources as first-class inventory, from any provider, without a second
asset system.

It answers one question, per customer, on demand: **what is running in my
clients' cloud accounts, who does it belong to, and what is it costing them?**

GLPI 11 has no cloud model at all — the only occurrences of the word in its
source are two icon names. This adds one, and deliberately adds it *once*: the
core plugin owns the resource model, the entity mapping, the lifecycle and the
money, and each cloud provider is a separate plugin that does nothing but speak
its own API.

| Plugin | Key | Role |
|---|---|---|
| `glpi-cloud` | `glpicloud` | this one — the model, the sweep, the UI, the money |
| `glpi-cloud-azure` | `glpicloudazure` | Microsoft Azure |
| `glpi-cloud-ovh` | `glpicloudovh` | OVHcloud |
| `glpi-cloud-aws` | `glpicloudaws` | later |
| `glpi-cloud-gcp` | `glpicloudgcp` | later |

## Why split

Three independent APIs on one release train means one settings page with
three-quarters of it irrelevant to any given customer, and one blast radius: a
change to the AWS pagination code ships to an estate that only runs Azure.

The seam is the **API boundary**. A provider plugin owns auth, endpoints,
pagination, throttling and its own vocabulary. This plugin owns everything
downstream of a normalised row, and makes **not one HTTP call to a cloud
endpoint**.

The test for whether that holds is in `tests/`: the whole core is exercised
against a fixture provider that reads JSON off disk. If a feature here cannot be
demonstrated without a real cloud account, provider knowledge has leaked.

## What it does with what it collects

**One canonical row per resource**, whatever it is — buckets, roles, security
groups, functions, key vaults and virtual machines alike. The long tail of cloud
types has no equivalent anywhere in GLPI, and a schema that only stored the
types we had mappings for would be quietly wrong exactly where somebody is
looking: the odd resource nobody remembered creating.

**Projection is opt-in, per type.** Where core genuinely models the thing better
— VM → `Computer`, managed database → `DatabaseInstance`, cluster → `Cluster` —
a resource can additionally maintain a native asset. Off by default, because
projecting a dev subscription of four thousand resources into Computers rewrites
the asset list the service desk depends on.

**Money goes into GLPI's own Management model.** A cloud account maps to a
native `Contract` with a `Supplier`; each closed billing period becomes one
`ContractCost` line, attributed to the account's `Budget`. Core's own budget
screens then count cloud spend with no reporting code of ours. Per-resource,
per-month history stays in this plugin's tables, because tens of thousands of
rows a month is not what Infocom is for.

**Currency is recorded as billed and never converted**, and unattributed spend —
marketplace charges, support, reservations — is kept as its own row rather than
dropped, because an account total that does not reconcile with the invoice will
not be trusted twice.

## What it never does

- **No control-plane writes, ever.** Every credential it asks for is read-only,
  and the account form says so where you type one in. It never starts, stops,
  tags, deletes or reconfigures anything in a customer's cloud.
- **No agent, and nothing in-guest.** What runs *inside* a cloud VM is
  glpi-osquery's question.
- **No posture scoring or CSPM.** A handful of glaring facts become alerts; a
  compliance product does not.
- **No forecasting.** We record what the provider billed.

## The rule that matters most

A resource missing from a sweep is only marked gone if that sweep **completed**.
A sweep that threw, or that ran out of its wall-clock budget, concludes nothing
about what is missing. Otherwise one throttled request deletes a customer's
inventory and the next sweep "rediscovers" all of it with today's first-seen
date — silently, and unrecoverably.

## The assistant can ask too

Where [glpi-ai](../glpi-ai) is installed, this plugin registers two read-only
tools with it:

| Tool | Answers |
|---|---|
| `cloud_resources` | What is running in this customer's accounts — state, region, tags, when it was last seen, and which GLPI asset it is projected onto |
| `cloud_spend` | What it cost in a month, per account and currency, and whether the figure is still provisional |

The first one closes a gap the assistant could not see around: every other tool
it has reads the CMDB, so for a customer whose file server is an Azure VM,
"no asset matches" is a true statement about GLPI and a false one about their
estate. Each resource reports its projection, which is what tells the model
whether the rest of its tools can reach the thing at all.

Spend is gated on the **account** right rather than the resource one, mirroring
the split this plugin already makes: seeing that a VM exists and seeing what the
customer pays for it are different permissions. The answer always says whether
a month is provisional, because a provider's running estimate quoted as the
bill is a number somebody will be held to.

**Nothing acts on the cloud, and nothing should from a tool.** Starting,
stopping, resizing or deleting a resource is not a read that went wrong, it is
an outage — and the credentials here are read-only by design, so a write tool
would be an argument for widening them. A technician who needs a resource
stopped uses the provider's console, with their own name against it.

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

**Never extend or implement a class of this plugin's.** A class whose parent
lives in a plugin that has been deactivated — or is mid-upgrade — is a fatal at
autoload time, and it takes out every page that touches it. Descriptors cross
the boundary as arrays; parameter type hints resolve lazily and are fine.

`tests/fixture-provider.php` is the worked example, including paging and
resumption: a provider hands back a cursor by calling `$ctx['checkpoint']($token)`,
and the core never looks inside it.

## Tests

```
tests/run.sh                       # pure PHP, no GLPI, no network
docker compose -p glpi exec glpi \
  php /var/www/glpi/plugins/glpicloud/tests/sync.php     # deliberate, writes to the DB
```

`registry.php` covers the provider contract — what is accepted, what is dropped
and what is said about it. `normalise.php` covers the checksum, which is the one
decision that says whether a six-hourly sweep of forty thousand resources writes
anything at all. `sync.php` covers the lifecycle and the money, including the
rule above.

## Install

```bash
# from the GLPI root — the directory has to be named for the plugin
# key, which is not the repository name
git clone https://github.com/bijstaan/glpi-cloud.git plugins/glpicloud
php bin/console plugin:install -u glpi glpicloud
php bin/console plugin:activate glpicloud
```

## Licence

GNU General Public License, version 3 or later — the same licence as GLPI.
This plugin is loaded into GLPI's process and extends its classes, so it is a
derivative work of GLPI and carries GLPI's licence. See [LICENSE](LICENSE).
