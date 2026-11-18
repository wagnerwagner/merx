panel.plugin("wagnerwagner/merx", {
	components: {
		/**
		 * One section of Merx’ Panel view: a list of items or a table
		 *
		 * The table is Kirby’s, with row headers in the first column, which
		 * `k-table` can’t do. Cells of type `code` show a value or each value of
		 * a list as code, linked to the row’s `link`. A row `type` colors it.
		 * Cells of type `pre` keep their line breaks and scroll beyond 4.5 lines.
		 */
		"k-merx-section": {
			props: {
				section: Object,
			},
			methods: {
				values(value) {
					return Array.isArray(value) ? value : [value];
				},
			},
			template: `
				<k-section :headline="section.headline">
					<k-items v-if="section.items" :items="section.items" />
					<div v-else class="k-table">
						<table>
							<thead>
								<tr>
									<th v-for="(column, key) in section.columns" :key="key" scope="col" :style="{ width: column.width }">{{ column.label }}</th>
								</tr>
							</thead>
							<tbody>
								<tr v-for="(row, index) in section.rows" :key="index">
									<template v-for="(column, key, position) in section.columns" :key="key">
										<th v-if="position === 0" scope="row">{{ row[key] }}</th>
										<td v-else-if="column.type === 'html'"><k-text :text="row[key]" /></td>
										<td v-else-if="column.type === 'pre'">
											<k-text size="tiny" style="padding-block: var(--spacing-1)">
												<k-code style="max-height: 9.2lh; overflow: auto; --code-color-back: var(--color-gray-200); --code-color-text: var(--color-gray-800, var(--color-black));">
													{{ row[key] }}
												</k-code>
											</k-text>
										</td>
										<td v-else-if="column.type === 'code'" :class="{ 'k-text': !row.type }">
											<k-link v-if="row.link" :to="row.link"><code :data-type="row.type">{{ row[key] }}</code></k-link>
											<span v-else style="display: flex; flex-wrap: wrap; gap: var(--spacing-1)">
												<code v-for="value in values(row[key])" :key="value" :data-type="row.type">{{ value }}</code>
											</span>
										</td>
										<td v-else>{{ row[key] }}</td>
									</template>
								</tr>
							</tbody>
						</table>
					</div>
				</k-section>
			`,
		},
		/**
		 * Merx’ Panel view. It only renders the sections `Wagnerwagner\Merx\SystemView` builds,
		 * those in a `grid` side by side, and adds what the browser `checks` find to the security items.
		 */
		"k-merx-view": {
			props: {
				checks: [Object, Array],
				sections: Array,
			},
			data() {
				return {
					issues: [],
				};
			},
			computed: {
				// The security items of the server, then those the browser found
				shown() {
					return this.sections.map((section) =>
						section.id === "security" ? { ...section, items: [...section.items, ...this.issues] } : section,
					);
				},
			},
			async mounted() {
				const { robots, sitemap } = this.checks ?? {};

				if (robots) {
					console.info("Merx checks robots.txt and sitemap.xml. Failed requests in the following console output are expected.");
				}

				// As a visitor: no Panel session.
				const get = (url) =>
					fetch(url, { cache: "no-store", credentials: "omit" }).catch(() => null);

				if (robots) {
					const response = await get(robots.url);
					if (response?.ok !== true) {
						this.issues.push(robots.missing);
					} else {
						const disallowed = (await response.text()).split("\n").some((line) => {
							const path = line.match(/^\s*disallow:\s*(\S+)/i)?.[1];
							return path !== undefined && robots.path.startsWith(path);
						});
						if (disallowed === false) {
							this.issues.push(robots.orders);
						}
					}
				}

				if (sitemap) {
					const response = await get(sitemap.url);
					if (response?.ok === true && (await response.text()).includes(sitemap.prefix)) {
						this.issues.push(sitemap.orders);
					}
				}
			},
			template: `
				<k-panel-inside class="k-merx-view">
					<k-header>Merx</k-header>
					<template v-for="(section, index) in shown" :key="index">
						<k-grid v-if="section.grid" variant="columns" style="margin-block: var(--columns-block-gap)">
							<k-column v-for="column in section.grid" :key="column.headline" width="1/2">
								<k-merx-section :section="column" />
							</k-column>
						</k-grid>
						<k-merx-section v-else :section="section" />
					</template>
				</k-panel-inside>
			`,
		},
	},
	fields: {
		/**
		 * A number field for a single pricing rule, an object field for several
		 */
		prices: {
			extends: "k-object-field",
			computed: {
				// Key of the only pricing rule, null when there are several
				rule() {
					const rules = Object.keys(this.fields ?? {});
					return rules.length === 1 ? rules[0] : null;
				},
				// The rule’s field with this field’s label, help and state
				numberField() {
					const { fields, value, ...field } = this.$props;
					const set = Object.entries(field).filter(([, prop]) => prop !== undefined && prop !== null);
					return { ...fields[this.rule], ...Object.fromEntries(set) };
				},
			},
			methods: {
				onNumber(price) {
					this.object = { ...this.object, [this.rule]: price };
					this.save();
				},
			},
			template: `
				<k-number-field
					v-if="rule"
					v-bind="numberField"
					:value="object[rule]"
					@input="onNumber"
				/>
				<k-object-field
					v-else
					v-bind="$props"
					@input="$emit('input', $event)"
				/>
			`,
		},
		taxrules: {
			extends: "k-select-field",
		},
	},
});
