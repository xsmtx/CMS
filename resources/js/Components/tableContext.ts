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
  /**
   * The header is a sort control. Only when the **server** sorts by this
   * key: a client-side sort of one page of a paginated list is a lie about
   * the other pages.
   */
  sortable?: boolean
  /**
   * Hide this column below a Tailwind breakpoint. For secondary facts
   * (created date, owner, region) on a table that must still fit a 1280px
   * laptop — never for the column that names the row or states its status.
   */
  hideBelow?: 'md' | 'lg' | 'xl'
  /**
   * Keep this column in place while the table scrolls sideways. One column,
   * the first — the one that says which row this is.
   */
  sticky?: boolean
}

/** The sort a table is showing. `null` is the server's default order. */
export interface TableSort {
  key: string
  direction: 'asc' | 'desc'
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
