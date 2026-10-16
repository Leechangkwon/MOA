// Section names may change while stored option values keep task membership stable.
export function optionLabel(prop, value) {
  const label = prop && prop.option_labels && Object.prototype.hasOwnProperty.call(prop.option_labels, value)
    ? prop.option_labels[value] : null;
  return typeof label === 'string' ? label : String(value);
}
