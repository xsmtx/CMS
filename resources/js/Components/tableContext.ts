import { type InjectionKey, type Ref } from 'vue'

/**
 * A column an operator can be shown, hidden, or scan numbers down.
 *
 * `key` is the contract between the header and the cells: a `<td>` carries
 * `data-col="<key>"`, and that is how a table whose rows are free-form
 * markup can still hide a column. Rows are markup rather than a column API
 * on purpose — every list here needs different cell content, and a generic
 * renderer would be configured around rather than used — so the attribute
 * is the seam.
 */
export interface TableColumn {
  key: string
  label: string
  /** Right-aligned, tabular figures. A column of totals reads as a column. */
  numeric?: boolean
  /**
   * The operator may hide it.
   *
   * Left off the column that names the row: a table whose first column can
   * be hidden is a table somebody can turn into rows of numbers belonging
   * to nothing.
   */
  optional?: boolean
  /** Present, but off until asked for. */
  offByDefault?: boolean
}

/**
 * What a row needs to know from the table it is in.
 *
 * Provided rather than passed: every caller would otherwise repeat
 * `:selected="selected.includes(row.id)" @toggle="toggle"` on every row of
 * every list, and the one that forgot would be a row that cannot be
 * selected with no error to say so.
 */
export interface TableContext {
  selectable: Ref<boolean>
  isSelected: (id: string) => boolean
  toggle: (id: string) => void
  /** The word for one row, for the checkbox's accessible name. */
  noun: Ref<string>
}

export const TABLE_CONTEXT: InjectionKey<TableContext> = Symbol('appTable')
