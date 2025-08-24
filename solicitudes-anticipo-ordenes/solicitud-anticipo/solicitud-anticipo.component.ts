// solicitud-anticipo.component.ts
import { Component, Input, Output, EventEmitter, OnInit, OnChanges, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, FormBuilder, FormGroup, Validators } from '@angular/forms';
import { DatosAnticipo } from './models/anticipo.model';

// Importar componentes específicos
import { EfectivoFormComponent } from './tipos/efectivo/efectivo.component';
import { ChequeFormComponent } from './tipos/cheque/cheque.component';
import { TransferenciaFormComponent } from './tipos/transferencia/transferencia.component';
import { DepositoFormComponent } from './tipos/deposito/deposito.component'; // Descomenta cuando esté disponible

/**
 * Componente para gestionar solicitudes de anticipo
 * Permite crear y editar solicitudes de diferentes tipos: efectivo, cheque, transferencia, depósito
 */
@Component({
  selector: 'app-solicitud-anticipo',
  standalone: true,
  imports: [
    CommonModule,
    ReactiveFormsModule,
    EfectivoFormComponent,
    ChequeFormComponent,
    TransferenciaFormComponent,
    DepositoFormComponent // Descomenta cuando esté disponible
  ],
  templateUrl: './solicitud-anticipo.component.html',
  styleUrl: './solicitud-anticipo.component.css'
})
export class SolicitudAnticipoComponent implements OnInit, OnChanges {

  /** Controla la visibilidad del modal */
  @Input() mostrar: boolean = false;

  /** Indica si el componente está en modo edición */
  @Input() modoEdicion: boolean = false;

  /** Datos para prellenar el formulario en modo edición */
  @Input() datosEdicion: DatosAnticipo | null = null;

  /** ID de la orden asociada */
  @Input() idOrden: number = 0;

  /** Tipo de solicitud */
  @Input() tipoSolicitud: number = 1;

  /** Evento emitido al cerrar el modal */
  @Output() cerrar = new EventEmitter<void>();

  /** Evento emitido al guardar (para el componente padre) */
  @Output() guardar = new EventEmitter<DatosAnticipo>();

  /** Evento emitido después de guardar exitosamente */
  @Output() guardado = new EventEmitter<DatosAnticipo>();

  /** Formulario para seleccionar el tipo de anticipo */
  formularioTipo!: FormGroup;

  /** Tipo de anticipo actualmente seleccionado */
  tipoSeleccionado: string = 'efectivo';

  /** Configuración de tipos de anticipo disponibles */
  readonly tiposDisponibles = [
    {
      tipo: 'efectivo',
      nombre: 'Efectivo',
      descripcion: 'Retiro de efectivo en agencia',
      color: 'blue'
    },
    {
      tipo: 'cheque',
      nombre: 'Cheque',
      descripcion: 'Emisión de cheque bancario',
      color: 'green'
    },
    {
      tipo: 'transferencia',
      nombre: 'Transferencia',
      descripcion: 'Transferencia electrónica',
      color: 'purple'
    },
    {
      tipo: 'deposito',
      nombre: 'Depósito',
      descripcion: 'Depósito a cuenta interna',
      color: 'sky'
    }
  ];

  constructor(private fb: FormBuilder) { }

  ngOnInit(): void {
    this.inicializarFormulario();
  }

  ngOnChanges(changes: SimpleChanges): void {
    // Asegurar que el formulario esté inicializado antes de usarlo
    if (!this.formularioTipo) {
      this.inicializarFormulario();
    }

    if (changes['modoEdicion'] || changes['datosEdicion']) {
      if (this.modoEdicion && this.datosEdicion) {
        this.tipoSeleccionado = this.datosEdicion.tipo;
        // Usar setTimeout para asegurar que el formulario esté completamente inicializado
        setTimeout(() => {
          this.formularioTipo.patchValue({ tipo: this.datosEdicion!.tipo });
        });
      } else {
        this.resetearFormulario();
      }
    }
  }

  /**
   * Inicializa el formulario de selección de tipo
   */
  private inicializarFormulario(): void {
    this.formularioTipo = this.fb.group({
      tipo: [this.tipoSeleccionado, Validators.required]
    });

    // Escuchar cambios en el tipo seleccionado
    this.formularioTipo.get('tipo')?.valueChanges.subscribe(tipo => {
      if (tipo) {
        this.tipoSeleccionado = tipo;
      }
    });
  }

  /**
   * Resetea el formulario y el estado del componente
   */
  private resetearFormulario(): void {
    this.tipoSeleccionado = 'efectivo';

    // Verificar que el formulario exista antes de usarlo
    if (this.formularioTipo) {
      this.formularioTipo.patchValue({ tipo: 'efectivo' });
    }
  }

  /**
   * Obtiene el color asociado a un tipo de anticipo
   * @param tipo - Tipo de anticipo
   * @returns Color correspondiente al tipo
   */
  obtenerColorTipo(tipo: string): string {
    const tipoEncontrado = this.tiposDisponibles.find(t => t.tipo === tipo);
    return tipoEncontrado?.color || 'blue';
  }

  /**
   * Obtiene el nombre legible de un tipo de anticipo
   * @param tipo - Tipo de anticipo
   * @returns Nombre legible del tipo
   */
  obtenerNombreTipo(tipo: string): string {
    const tipoEncontrado = this.tiposDisponibles.find(t => t.tipo === tipo);
    return tipoEncontrado?.nombre || tipo;
  }

  /**
 * Obtiene las clases CSS dinámicas para el label del tipo
 * @param tipo - Tipo de anticipo
 * @returns String con las clases CSS adicionales
 */
  obtenerClasesLabel(tipo: string): string {
    const baseClasses = 'flex flex-col items-center p-4 border-2 rounded-lg cursor-pointer transition-all duration-200 hover:bg-gray-50 hover:border-gray-300';

    if (this.tipoSeleccionado === tipo) {
      switch (tipo) {
        case 'efectivo':
          return `${baseClasses} border-blue-500 bg-blue-50 text-blue-700`;
        case 'cheque':
          return `${baseClasses} border-green-500 bg-green-50 text-green-700`;
        case 'transferencia':
          return `${baseClasses} border-purple-500 bg-purple-50 text-purple-700`;
        case 'deposito':
          return `${baseClasses} border-sky-500 bg-sky-50 text-sky-700`;
        default:
          return `${baseClasses} border-blue-500 bg-blue-50 text-blue-700`;
      }
    }

    return `${baseClasses} border-gray-200 bg-white text-gray-900`;
  }

  /**
   * Verifica si un tipo está seleccionado
   * @param tipo - Tipo a verificar
   * @returns true si está seleccionado
   */
  esTipoSeleccionado(tipo: string): boolean {
    return this.tipoSeleccionado === tipo;
  }

  /**
   * Maneja el evento de guardar datos
   * @param datos - Datos del anticipo a guardar
   */
  onGuardar(datos: DatosAnticipo): void {
    // Emitir evento de guardar para que el componente padre lo maneje
    this.guardar.emit(datos);
  }

  /**
   * Maneja el evento de cancelar
   */
  onCancelar(): void {
    this.cerrarModal();
  }

  /**
   * Cierra el modal y resetea el estado
   */
  cerrarModal(): void {
    this.resetearFormulario();
    this.cerrar.emit();
  }

  /**
   * Callback para manejar el guardado exitoso
   * @param datos - Datos guardados
   */
  onGuardadoExitoso(datos: DatosAnticipo): void {
    this.guardado.emit(datos);
    this.cerrarModal();
  }
}