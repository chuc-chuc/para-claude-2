// tipos/cheque/cheque.component.ts

import { Component } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ReactiveFormsModule, Validators } from '@angular/forms';
import { BaseFormComponent } from '../../shared/base.component';
import { DatosAnticipo } from '../../models/anticipo.model';

@Component({
  selector: 'app-cheque',
  standalone: true,
  imports: [CommonModule, ReactiveFormsModule],
  templateUrl: './cheque.component.html',
  styleUrl: './cheque.component.css'
})
export class ChequeFormComponent extends BaseFormComponent {

  inicializarFormulario(): void {
    this.formulario = this.fb.group({
      monto: [null, [Validators.required, Validators.min(0.01)]],
      nombre_beneficiario: ['', [Validators.required, Validators.minLength(3)]],
      consignacion: ['', Validators.required],
      comentario: ['', Validators.required],
      id_orden: [this.idOrden],
      tipo_solicitud: [this.tipoSolicitud]
    });
  }

  cargarDatosExternos(): void {
    // Cheque no necesita cargar datos externos (agencias, bancos, etc.)
  }

  override cargarDatos(datos: DatosAnticipo): void {
    super.cargarDatos(datos);

    // Para la consignación, si viene no_negociable=true, ponemos "No Negociable"
    let consignacion = datos.consignacion || '';
    if (datos.no_negociable) {
      consignacion = 'No Negociable';
    } else if (consignacion === '') {
      consignacion = 'Negociable';
    }

    this.formulario.patchValue({
      nombre_beneficiario: datos.nombre_beneficiario || '',
      consignacion: consignacion
    });
  }

  obtenerDatos(): DatosAnticipo {
    const formData = this.formulario.value;

    return {
      tipo: 'cheque',
      monto: formData.monto,
      comentario: formData.comentario,
      nombre_beneficiario: formData.nombre_beneficiario,
      consignacion: formData.consignacion,
      no_negociable: formData.consignacion === 'No Negociable',
      id_orden: formData.id_orden || this.idOrden,
      tipo_solicitud: formData.tipo_solicitud || this.tipoSolicitud,
      concepto: formData.comentario,
      solicitante: 'Usuario actual',
      detalle: formData.comentario
    };
  }
}