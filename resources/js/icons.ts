/**
 * The icon set, as concepts rather than drawings.
 *
 * The left-hand side is what a screen asks for; the right-hand side is this
 * month's answer. A screen that named `PhShoppingCart` directly would make
 * changing the drawing a find-and-replace across forty files, and would let
 * two screens pick two different carts.
 *
 * **One family, one weight.** Phosphor at `regular`, which is a 1.5px stroke
 * on a 24px grid — the same optical weight as the hairlines this product
 * draws its structure with. Mixing weights is the fastest way to make a set
 * of icons look assembled rather than chosen.
 *
 * The map is static so the bundle carries only what is named here. A dynamic
 * import keyed on a string would ship the whole library.
 *
 * Nothing is hand-drawn. A path somebody nudged by eye is a path nobody can
 * match when the next icon is needed.
 */
import {
  PhArrowClockwise,
  PhArrowSquareOut,
  PhArrowsClockwise,
  PhBell,
  PhBriefcase,
  PhBuildings,
  PhCaretDown,
  PhCaretRight,
  PhCheck,
  PhCheckCircle,
  PhClockCounterClockwise,
  PhCopySimple,
  PhCreditCard,
  PhCube,
  PhDatabase,
  PhDotsThree,
  PhDownloadSimple,
  PhEnvelopeSimple,
  PhFileText,
  PhFunnel,
  PhGauge,
  PhGear,
  PhGlobe,
  PhHardDrives,
  PhInfo,
  PhLifebuoy,
  PhLightning,
  PhListChecks,
  PhMagnifyingGlass,
  PhNote,
  PhPaperPlaneTilt,
  PhPencilSimple,
  PhPlugsConnected,
  PhPlus,
  PhPuzzlePiece,
  PhQuestion,
  PhReceipt,
  PhShieldCheck,
  PhShoppingCart,
  PhSignOut,
  PhSlidersHorizontal,
  PhTag,
  PhTicket,
  PhTrash,
  PhUser,
  PhUserCircle,
  PhUsers,
  PhWarningCircle,
  PhWrench,
  PhX,
} from '@phosphor-icons/vue'
import type { Component } from 'vue'

export const ICONS = {
  // Navigation
  dashboard: PhGauge,
  clients: PhUsers,
  orders: PhShoppingCart,
  billing: PhCreditCard,
  support: PhLifebuoy,
  utilities: PhWrench,
  setup: PhGear,
  services: PhCube,
  domains: PhGlobe,
  servers: PhHardDrives,
  catalog: PhTag,
  automation: PhLightning,
  modules: PhPuzzlePiece,
  organizations: PhBuildings,
  reseller: PhBriefcase,

  // Records
  invoice: PhReceipt,
  document: PhFileText,
  ticket: PhTicket,
  note: PhNote,
  user: PhUser,
  account: PhUserCircle,
  todo: PhListChecks,
  database: PhDatabase,
  connection: PhPlugsConnected,

  // Actions
  search: PhMagnifyingGlass,
  add: PhPlus,
  edit: PhPencilSimple,
  remove: PhTrash,
  filter: PhFunnel,
  refresh: PhArrowClockwise,
  sync: PhArrowsClockwise,
  send: PhPaperPlaneTilt,
  download: PhDownloadSimple,
  settings: PhSlidersHorizontal,
  more: PhDotsThree,
  external: PhArrowSquareOut,
  signOut: PhSignOut,
  mail: PhEnvelopeSimple,
  security: PhShieldCheck,
  history: PhClockCounterClockwise,
  copy: PhCopySimple,
  notifications: PhBell,

  // States and chrome
  ok: PhCheckCircle,
  check: PhCheck,
  warning: PhWarningCircle,
  info: PhInfo,
  help: PhQuestion,
  close: PhX,
  chevronDown: PhCaretDown,
  chevronRight: PhCaretRight,
} satisfies Record<string, Component>

export type IconName = keyof typeof ICONS
