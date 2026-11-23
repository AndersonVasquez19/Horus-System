import {
  Award,
  BarChart3,
  Bell,
  BookOpen,
  Bot,
  CalendarRange,
  ClipboardCheck,
  ClipboardList,
  Download,
  FileText,
  History,
  LayoutDashboard,
  MessageSquareWarning,
  Settings,
  ShieldCheck,
  UserCog,
  UserPlus,
  Users,
  Wallet,
} from 'lucide-react';

/**
 * Registro de iconos permitidos en la navegación de los paneles.
 *
 * La navegación se declara en layouts de servidor y cruza a un componente de cliente, así que
 * el modelo lleva una clave serializable (`NombreIconoPanel`) y no el componente.
 */
export const ICONOS_PANEL = {
  inicio: LayoutDashboard,
  personas: Users,
  documentos: FileText,
  avisos: Bell,
  ajustes: Settings,
  cursos: BookOpen,
  registros: ClipboardList,
  seguridad: ShieldCheck,
  matriculas: UserPlus,
  pagos: Wallet,
  certificados: Award,
  ediciones: CalendarRange,
  reportes: BarChart3,
  reclamaciones: MessageSquareWarning,
  asistente: Bot,
  aprobaciones: ClipboardCheck,
  usuarios: UserCog,
  auditoria: History,
  respaldo: Download,
} as const;

export type NombreIconoPanel = keyof typeof ICONOS_PANEL;
