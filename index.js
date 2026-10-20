panel.plugin("wagnerwagner/merx", {
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
					const set = Object.entries(field).filter(([, prop]) => prop !== undefined);
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
